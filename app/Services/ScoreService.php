<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Models\Score as ScoreModel;
use App\Contracts\LoggerInterface;
use Core\Cache;
use App\Services\OutboxService;
use App\Services\Cache\CacheInvalidationService;
use App\Enums\ScoreDomain;

/**
 * Unified Score Service
 * 
 * جایگزین تجمیع شده برای:
 * 1. App\Services\Gamification\ScoreService
 * 2. App\Services\AntiFraud\FraudScoreService
 * 3. App\Services\InfluencerReputationService
 */
class ScoreService extends BaseService
{
    public function __construct(
        private Database $db,
        private ScoreModel $scoreModel,
        protected LoggerInterface $logger,
        private Cache $cache,
        private ?OutboxService $outboxService = null,
        private ?CacheInvalidationService $cacheInvalidation = null
    ) {
        parent::__construct($logger);
    }

    /**
     * ثبت متمرکز تغییرات امتیاز با Outbox و Cache Invalidation
     */
    public function applyDelta(string $entityType, int $entityId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        $domain = ScoreDomain::normalize($domain);

        $ok = $this->transaction(function() use ($entityType, $entityId, $domain, $delta, $source, $meta) {
            // 1. ثبت در Ledger (Immutable)
            $success = $this->scoreModel->addEvent([
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'domain'      => $domain,
                'delta'       => $delta,
                'source'      => $source,
                'meta'        => $meta
            ]);

            // 2. به‌روزرسانی Projection
            if ($success && $entityType === 'user') {
                $stmt = $this->db->prepare("
                    INSERT INTO user_scores (user_id, domain, score, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE score = score + VALUES(score), updated_at = NOW()
                ");
                $stmt->execute([$entityId, $domain, $delta]);
            }

            // 3. ثبت Transactional Outbox (جلوگیری از Circular Dependency)
            if ($success) {
                $outbox = $this->outboxService ?? app(OutboxService::class);
                $outbox->record('score', $entityId, "score.adjusted", [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'domain'      => $domain,
                    'delta'       => $delta,
                    'source'      => $source,
                    'meta'        => $meta
                ]);
            }

            return $success;
        });

        // 4. Cache Invalidation (خارج از تراکنش برای جلوگیری از Race Condition)
        if ($ok) {
            $this->invalidateScoreCache($entityType, $entityId, $domain);
        }

        return $ok;
    }

    /**
     * خواندن امتیاز با احتساب بافر موقت (Real-time Projection)
     */
    public function getScore(string $entityType, int $entityId, string $domain): float
    {
        $domain = ScoreDomain::normalize($domain);
        $cacheKey = "score:{$entityType}:{$entityId}:{$domain}";
        $tempKey = "temp_{$domain}_score:{$entityId}";

        $bufferedDelta = 0.0;
        try {
            $bufferedDelta = (float)$this->cache->get($tempKey, 0.0);
        } catch (\Throwable $e) {}

        $projectionScore = null;
        try {
            $cached = $this->cache->get($cacheKey, null);
            if ($cached !== null) {
                $projectionScore = (float)$cached;
            }
        } catch (\Throwable $e) {}

        if ($projectionScore === null) {
            try {
                if ($entityType === 'user') {
                    $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = ? LIMIT 1");
                    $stmt->execute([$entityId, $domain]);
                    $value = $stmt->fetchColumn();
                    $projectionScore = $value !== false ? (float)$value : $this->scoreModel->getDomainScore($entityId, $domain);
                } else {
                    $projectionScore = $this->scoreModel->getTotal($entityId, $entityType, $domain);
                }

                $this->cache->putSeconds($cacheKey, $projectionScore, 300);
            } catch (\Throwable $e) {
                $projectionScore = 0.0;
            }
        }

        return $projectionScore + $bufferedDelta;
    }

    private function invalidateScoreCache(string $entityType, int $entityId, string $domain): void
    {
        $keys = [
            "score:{$entityType}:{$entityId}:{$domain}",
            "temp_{$domain}_score:{$entityId}"
        ];

        $this->invalidateCache($keys, function($cache) use ($entityId, $domain) {
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateScore($entityId, $domain);
            }
        });
    }

    // =====================================
    // Legacy Alias Methods for Refactoring
    // =====================================

    public function addScore(int $userId, string|\BackedEnum $context, float $amount, string $reason): bool
    {
        $ctx = $context instanceof \BackedEnum ? (string)$context->value : (string)$context;
        return $this->applyDelta('user', $userId, 'score_' . $ctx, $amount, $reason);
    }

    public function deductScore(int $userId, string|\BackedEnum $context, float $amount, string $reason): bool
    {
        $ctx = $context instanceof \BackedEnum ? (string)$context->value : (string)$context;
        return $this->applyDelta('user', $userId, 'score_' . $ctx, -$amount, $reason);
    }

    public function getTotalScore(int $userId, string|\BackedEnum $context): float
    {
        $ctx = $context instanceof \BackedEnum ? (string)$context->value : (string)$context;
        return $this->getScore('user', $userId, 'score_' . $ctx);
    }

    public function getFraudScore(int $userId): float
    {
        return $this->getScore('user', $userId, ScoreDomain::Fraud->value);
    }

    public function incrementFraudRawScore(int $userId, float $delta, string $source, array $meta = []): bool
    {
        return $this->applyDelta('user', $userId, ScoreDomain::Fraud->value, $delta, $source, $meta);
    }
}
