<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Models\Score as ScoreModel;
use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\EventDispatcher;
use App\Services\Cache\CacheInvalidationService;
use App\Enums\ScoreDomain;
use App\Events\ScoreUpdatedEvent;

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
        private ScoreModel $scoreModel,
        Database $db,
        LoggerInterface $logger,
        Cache $cache,
        EventDispatcher $eventDispatcher,
        private ?CacheInvalidationService $cacheInvalidation = null
    ) {
        // انتقال زیرساخت‌ها به BaseService و حذف OutboxService
        parent::__construct($logger, null, $db, null, null, $cache, null, $eventDispatcher);
    }

    /**
     * ثبت متمرکز تغییرات امتیاز با Outbox و Cache Invalidation
     */
    public function applyDelta(string $entityType, int $entityId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        $domain = ScoreDomain::normalize($domain);

        $eventPayload = null;

        $ok = $this->transaction(function() use ($entityType, $entityId, $domain, $delta, $source, $meta, &$eventPayload) {
            // 1. ثبت در Ledger (Immutable) و ایجاد قفل برای پایداری همزمانی
            $success = $this->scoreModel->addEvent([
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'domain'      => $domain,
                'delta'       => $delta,
                'source'      => $source,
                'meta'        => $meta
            ]);

            // 2. به‌روزرسانی Projection با استفاده از قفل ردیفی جهت جلوگیری از Race Condition
            if ($success && $entityType === 'user') {
                // ابتدا ردیف را قفل می‌کنیم (یا ایجاد می‌کنیم)
                $this->db->prepare("INSERT IGNORE INTO user_scores (user_id, domain, score, updated_at) VALUES (?, ?, 0, NOW())")
                         ->execute([$entityId, $domain]);
                
                $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = ? FOR UPDATE")
                         ->execute([$entityId, $domain]);

                $stmt = $this->db->prepare("
                    UPDATE user_scores 
                    SET score = score + ?, updated_at = NOW() 
                    WHERE user_id = ? AND domain = ?
                ");
                $stmt->execute([$delta, $entityId, $domain]);
            }

            // 3. آماده‌سازی داده رویداد — dispatch بعد از commit انجام می‌شود
            if ($success) {
                $newScore = null;
                if ($entityType === 'user') {
                    try {
                        $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = ? LIMIT 1");
                        $stmt->execute([$entityId, $domain]);
                        $val = $stmt->fetchColumn();
                        $newScore = $val !== false ? (float)$val : null;
                    } catch (\Throwable $e) {
                        $newScore = null;
                    }
                }

                $oldScore = $newScore !== null ? $newScore - $delta : 0.0;

                // ذخیره snapshot برای dispatch بعد از commit
                $eventPayload = [
                    'entityId' => $entityId,
                    'oldScore' => (float)$oldScore,
                    'newScore' => $newScore !== null ? (float)$newScore : (float)$delta,
                    'source'   => $source,
                ];
            }

            return $success;
        });

        // 4. 🚀 dispatch رویداد بعد از commit تراکنش — جلوگیری از بلاک شدن تراکنش توسط handler
        if ($ok && $eventPayload !== null) {
            $this->eventDispatcher->dispatchAsync(ScoreUpdatedEvent::class, new ScoreUpdatedEvent(
                $eventPayload['entityId'],
                $eventPayload['oldScore'],
                $eventPayload['newScore'],
                $eventPayload['source']
            ));
        }

        // 5. Cache Invalidation (خارج از تراکنش برای جلوگیری از Race Condition)
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
