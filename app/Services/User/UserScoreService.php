<?php

declare(strict_types=1);

namespace App\Services\User;

use Core\Database;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\Queue;
use App\Jobs\UpdateFraudScoreJob;

use App\Models\Score as ScoreModel;

class UserScoreService extends \App\Services\BaseService
{
    // HIGH-05: Strict Scoring Domain Whitelist prevents logic injections into scoring buckets
    private const ALLOWED_DOMAINS = ['fraud', 'task', 'trust', 'referral', 'activity', 'loyalty'];

    public function __construct(
        private Database $db,
        private RiskPolicyService $policyService,
        protected LoggerInterface $logger,
        private Cache $cache,
        private Queue $queue,
        private ScoreModel $scoreModel
    ) {
        parent::__construct($logger);
    }

    private function validateDomain(string $domain): void
    {
        if (!\in_array($domain, self::ALLOWED_DOMAINS, true)) {
            throw new \InvalidArgumentException("Unsupported or unauthorized score domain: {$domain}");
        }
    }

    public function applyEventDelta(int $userId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        $this->validateDomain($domain);

        // MED-06: Offload physical database persistence for ALL score domains to background workers for high responsiveness
        // 1. Pre-register in consistent cache block
        $cacheKey = "temp_{$domain}_score:{$userId}";
        $this->cache->incrementFloat($cacheKey, $delta);

        // 2. Dispatch physical async execution to safe queue worker
        return $this->queue->push(UpdateFraudScoreJob::class, [
            'user_id' => $userId,
            'delta'   => $delta,
            'domain'  => $domain,
            'source'  => $source,
            'meta'    => $meta
        ]);
    }

    /**
     * نوشتن نهایی و فیزیکی تغییرات در پایگاه داده
     */
    public function commitDeltaToDatabase(int $userId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        try {
            $this->validateDomain($domain);

            $stmt = $this->db->prepare("
                INSERT INTO user_scores (user_id, domain, score, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE score = score + VALUES(score), updated_at = NOW()
            ");
            $ok = $stmt->execute([$userId, $domain, $delta]);
            
            // Clean/deduct cache buffer upon backend persistence to prevent double counts during get() execution
            if ($ok) {
                $this->cache->incrementFloat("temp_{$domain}_score:{$userId}", -$delta);
            }
            
            return $ok;
        } catch (\Throwable $e) {
            $this->logger->error('user_score.commit_db.failed', [
                'user_id' => $userId,
                'domain' => $domain,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Unifies database tracking and real-time cache buffers to represent real-time scoring totals
     */
    public function getScore(int $userId, string $domain): float
    {
        $this->validateDomain($domain);

        try {
            $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = ? LIMIT 1");
            $stmt->execute([$userId, $domain]);
            $dbScore = (float)$stmt->fetchColumn();

            $cachedDelta = (float)$this->cache->get("temp_{$domain}_score:{$userId}", 0.0);

            return $dbScore + $cachedDelta;
        } catch (\Throwable $ignore) {
            return (float)$this->cache->get("temp_{$domain}_score:{$userId}", 0.0);
        }
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
