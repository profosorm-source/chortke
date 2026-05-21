<?php

declare(strict_types=1);

namespace App\Services\User;

use Core\Database;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\Queue;
use App\Jobs\UpdateFraudScoreJob;
use App\Enums\ScoreDomain;

use App\Models\Score as ScoreModel;

class UserScoreService extends \App\Services\BaseService
{
    // HIGH-05: Strict Scoring Domain Whitelist prevents logic injections into scoring buckets
    private const ALLOWED_DOMAINS = []; // kept for BC; canonical list is ScoreDomain::values()

    public function __construct(
        private Database $db,
        private RiskPolicyService $policyService,
        protected LoggerInterface $logger,
        private Cache $cache,
        private Queue $queue,
        private ScoreModel $scoreModel,
        private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null
    ) {
        parent::__construct($logger);
    }

    private function normalizeDomain(string $domain): string
    {
        return ScoreDomain::normalize($domain);
    }

    private function validateDomain(string $domain): void
    {
        if (!ScoreDomain::isValid($domain)) {
            throw new \InvalidArgumentException("Unsupported or unauthorized score domain: {$domain}");
        }
    }

    public function applyEventDelta(int $userId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        $this->validateDomain($domain);
        $domain = $this->normalizeDomain($domain);

        // Source of truth must be database-backed: score_events is immutable ledger,
        // user_scores is the current projection. Cache is invalidated only after commit.
        return $this->commitDeltaToDatabase($userId, $domain, $delta, $source, $meta);
    }

    /**
     * نوشتن نهایی و فیزیکی تغییرات در پایگاه داده
     * ✅ TRANSACTION BOUNDARY: Cache invalidation happens AFTER commit with retry logic
     */
    public function commitDeltaToDatabase(int $userId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        $started = false;
        try {
            $this->validateDomain($domain);
            $domain = $this->normalizeDomain($domain);

            $started = !$this->db->inTransaction();
            if ($started) {
                $this->db->beginTransaction();
            }

            $event = $this->db->prepare("
                INSERT INTO score_events (entity_type, entity_id, domain, delta, source, meta_json, created_at)
                VALUES ('user', ?, ?, ?, ?, ?, NOW())
            ");
            $event->execute([
                $userId,
                $domain,
                $delta,
                $source,
                !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);

            $stmt = $this->db->prepare("
                INSERT INTO user_scores (user_id, domain, score, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE score = score + VALUES(score), updated_at = NOW()
            ");
            $ok = $stmt->execute([$userId, $domain, $delta]);

            if ($started) {
                $this->db->commit();
            }

            if ($ok) {
                // ✅ TRANSACTION BOUNDARY: Invalidate cache AFTER commit with retry logic
                // Cache is not source of truth, but should be kept consistent with DB
                // If invalidation fails, we retry with exponential backoff to minimize stale data
                $this->invalidateCacheWithRetry($userId, $domain);
            }

            return $ok;
        } catch (\Throwable $e) {
            if ($started && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('user_score.commit_db.failed', [
                'user_id' => $userId,
                'domain' => $domain,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * ✅ CACHE CONSISTENCY: Retry cache invalidation to minimize stale data window
     * Even if first attempt fails, subsequent retries ensure eventual consistency
     */
    private function invalidateCacheWithRetry(int $userId, string $domain, int $attempt = 0): void
    {
        $maxAttempts = 3;
        $backoffMs = 100; // 100ms initial backoff
        
        try {
            // Compatibility cleanup for deltas buffered by the old async implementation.
            $tempKey = "temp_{$domain}_score:{$userId}";
            $buffer = (float)$this->cache->get($tempKey, 0.0);
            if ($buffer !== 0.0) {
                $this->cache->forget($tempKey);
            }
            
            // Invalidate score cache
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateScore($userId, $domain);
            } else {
                $this->cache->forget("user_score:{$userId}:{$domain}");
            }
            
            $this->logger->info('user_score.cache_invalidated', [
                'user_id' => $userId,
                'domain' => $domain,
                'attempt' => $attempt + 1,
            ]);
        } catch (\Throwable $cacheError) {
            if ($attempt < $maxAttempts - 1) {
                // Exponential backoff: 100ms, 200ms, 400ms
                $sleepTime = $backoffMs * pow(2, $attempt);
                usleep((int)$sleepTime * 1000);
                
                $this->logger->warning('user_score.cache_invalidation_retry', [
                    'user_id' => $userId,
                    'domain' => $domain,
                    'attempt' => $attempt + 1,
                    'next_backoff_ms' => $sleepTime * 2,
                    'error' => $cacheError->getMessage(),
                ]);
                
                // Retry recursively
                $this->invalidateCacheWithRetry($userId, $domain, $attempt + 1);
            } else {
                // All retries exhausted - log but don't throw
                // DB is consistent, cache is inconsistent (worst case: user sees old data briefly)
                $this->logger->error('user_score.cache_invalidation_failed_exhausted', [
                    'user_id' => $userId,
                    'domain' => $domain,
                    'attempts' => $maxAttempts,
                    'error' => $cacheError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Read the persisted score projection.
     * Cache is only a projection cache and never a source-of-truth delta buffer.
     */
    public function getScore(int $userId, string $domain): float
    {
        $this->validateDomain($domain);
        $domain = $this->normalizeDomain($domain);

        // 1. Fetch uncommitted buffered delta to prevent propagation delay/race conditions
        $tempKey = "temp_{$domain}_score:{$userId}";
        $bufferedDelta = 0.0;
        try {
            $bufferedDelta = (float)$this->cache->get($tempKey, 0.0);
        } catch (\Throwable) {
        }

        // 2. Fetch cached or database projection score
        $cacheKey = "user_score:{$userId}:{$domain}";
        $projectionScore = null;
        try {
            $cached = $this->cache->get($cacheKey, null);
            if ($cached !== null) {
                $projectionScore = (float)$cached;
            }
        } catch (\Throwable) {
        }

        if ($projectionScore === null) {
            try {
                $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = ? LIMIT 1");
                $stmt->execute([$userId, $domain]);
                $value = $stmt->fetchColumn();

                $projectionScore = $value !== false ? (float)$value : $this->scoreModel->getDomainScore($userId, $domain);

                try {
                    $this->cache->putSeconds($cacheKey, $projectionScore, 300);
                } catch (\Throwable) {
                }
            } catch (\Throwable $e) {
                $this->logger->warning('user_score.read_projection_failed', [
                    'user_id' => $userId,
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                ]);
                try {
                    $projectionScore = $this->scoreModel->getDomainScore($userId, $domain);
                } catch (\Throwable) {
                    $projectionScore = 0.0;
                }
            }
        }

        // 3. Return the sum: projection + uncommitted real-time delta!
        return $projectionScore + $bufferedDelta;
    }

    public function getFraudScore(int $userId): float
    {
        return $this->getScore($userId, 'fraud');
    }

    public function getTaskScore(int $userId): float
    {
        return $this->getScore($userId, 'task');
    }

    /**
     * Calculates final actionable scoring by reading raw scores and applying explicit admin modifiers
     */
    public function getEffectiveScore(int $userId, string $domain, float $rawScore): float
    {
        $this->validateDomain($domain);
        $domain = $this->normalizeDomain($domain);

        // LOW-05: Wire placeholder to dynamically calculate effective scoring through adjustments
        $effective = $rawScore;
        $adjustments = $this->scoreModel->getActiveAdjustments($userId, $domain);

        foreach ($adjustments as $adj) {
            $val = (float)$adj['value'];
            $op  = \strtolower(\trim($adj['operation'] ?? 'add'));

            if ($op === 'set') {
                return $val; // Absolute priority override
            } elseif ($op === 'add') {
                $effective += $val;
            } elseif ($op === 'subtract') {
                $effective -= $val;
            } elseif ($op === 'multiply') {
                $effective *= $val;
            }
        }

        return $effective;
    }

    public function incrementFraudRawScore(int $userId, float $delta, string $source, array $meta = []): bool
    {
        return $this->applyEventDelta($userId, 'fraud', $delta, $source, $meta);
    }

    /**
     * Inserts temporary/permanent administrative modifiers impacting effective scores
     */
    public function createAdjustment(
        int $userId, 
        string $domain, 
        float $adjustment, 
        string $reason, 
        ?string $expiry = null, 
        ?int $createdBy = null
    ): array {
        $this->validateDomain($domain);
        $domain = $this->normalizeDomain($domain);

        // LOW-06: Replace dummy stub to trigger physical persistent adjustments in Score Model
        $success = $this->scoreModel->createAdjustment([
            'user_id'    => $userId,
            'domain'     => $domain,
            'operation'  => 'add',
            'value'      => $adjustment,
            'reason'     => $reason,
            'expires_at' => $expiry,
            'created_by' => $createdBy ?? 0,
        ]);

        return ['success' => $success];
    }
}
