<?php

declare(strict_types=1);

namespace App\Services\Score;

use App\Models\Score as ScoreModel;
use Core\EventDispatcher;
use App\Enums\ScoreDomain;
use App\Events\ScoreDeltaAppendedEvent;
use App\Services\AntiFraud\FraudDetectionService;

/**
 * ScoreCommandService (Write Model / Command Side)
 * 
 * Handles ONLY the addition of score events to the Event Store (score_events ledger).
 * It does NOT update the read projection synchronously to prevent DB locks,
 * but it DOES update the Redis Cache atomic hash.
 */
class ScoreCommandService
{
    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Cache $cache;
    private \App\Contracts\LoggerInterface $logger;
    private ScoreModel $scoreModel;
    private \Core\RateLimiter $rateLimiter;
    private ?FraudDetectionService $fraudService;
    private ?\Core\TransactionWrapper $transactionWrapper;
    private ?\App\Services\OutboxService $outbox;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        ScoreModel $scoreModel,
        \Core\RateLimiter $rateLimiter,
        ?FraudDetectionService $fraudService = null,
        ?\Core\TransactionWrapper $transactionWrapper = null,
        ?\App\Services\OutboxService $outbox = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->scoreModel = $scoreModel;
        $this->rateLimiter = $rateLimiter;
        $this->fraudService = $fraudService;
        $this->transactionWrapper = $transactionWrapper;
        $this->outbox = $outbox;

            }

    /**
     * Appends a score delta to the event ledger and dispatches an event.
     */
    public function applyDelta(string $entityType, int $entityId, string $domain, float $delta, string $source, array $meta = [], ?string $idempotencyKey = null): bool
    {
        // 🛡️ Idempotency Check
        if ($idempotencyKey) {
            $idemKey = "score_idemp:{$idempotencyKey}";
            try {
                $redis = $this->cache->redis();
                if ($redis) {
                    if (!$redis->setnx($idemKey, 1)) {
                        $this->logger->info('score.idempotent_skip', ['idempotency_key' => $idempotencyKey]);
                        return true; // Already processed
                    }
                    $redis->expire($idemKey, 86400 * 7); // Retain for 7 days
                }
            } catch (\Throwable $e) {}
        }

        // 🛡️ Rate Limit: Prevent high-frequency score generation spam
        if (!$this->rateLimiter->attempt("score_apply_{$entityType}_{$entityId}_{$domain}", 60, 1, false)) {
            $this->logger->warning('score.rate_limit_exceeded', ['entity_type' => $entityType, 'entity_id' => $entityId]);
            return false;
        }

        $domain = ScoreDomain::normalize($domain);

        // AntiFraud Check for high-risk domains
        if ($this->fraudService !== null && $entityType === 'user' && $delta > 0) {
            $highRiskDomains = [ScoreDomain::Task->value, ScoreDomain::SocialTrust->value, ScoreDomain::Referral->value];
            if (in_array($domain, $highRiskDomains, true)) {
                try {
                    $fraudScore = $this->fraudService->calculateFraudScore($entityId);
                    
                    // If the user has a high fraud score, we log and penalize/block the delta
                    if ($fraudScore >= 85) {
                        $this->logger->warning('antifraud.score_blocked', [
                            'user_id' => $entityId,
                            'domain' => $domain,
                            'delta' => $delta,
                            'fraud_score' => $fraudScore
                        ]);
                        return false;
                    } elseif ($fraudScore >= 50) {
                        $delta = $delta * 0.5;
                        $meta['antifraud_penalty'] = true;
                        $meta['fraud_score'] = $fraudScore;
                    }
                } catch (\Throwable $e) {
                    // Decoupling: Do not block score entirely if fraud service is down
                    $this->logger->warning('antifraud.service_unavailable', ['error' => $e->getMessage()]);
                }
            }
        }

        // 1. Immutable Event Sourcing & Projection Update (Transactional Outbox)
        $executor = function() use ($entityType, $entityId, $domain, $delta, $source, $meta) {
            $success = $this->scoreModel->addEvent([
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'domain'      => $domain,
                'delta'       => $delta,
                'source'      => $source,
                'meta'        => $meta
            ]);

            if ($success && $this->outbox) {
                $this->outbox->record(
                    'score_projection',
                    $entityId,
                    ScoreDeltaAppendedEvent::class,
                    ['entity_type' => $entityType, 'entity_id' => $entityId, 'domain' => $domain, 'delta' => $delta, 'source' => $source]
                );
            }

            return $success;
        };

        // Execute atomically if wrapper exists
        $success = $this->transactionWrapper 
            ? $this->transactionWrapper->runWithRetry($executor)
            : $executor();

        if ($success) {
            // 2. Atomically update the Redis Hash for immediate read-after-write consistency
            try {
                $redis = $this->cache->redis();
                if ($redis) {
                    $cacheKey = "score:{$entityType}:{$entityId}"; // Fix Cache Key Corruption
                    $redis->hIncrByFloat($cacheKey, $domain, $delta);
                    $redis->expire($cacheKey, 3600);
                }
            } catch (\Throwable $e) {
                // Ignore cache errors, fallback to DB
            }

            // 3. Dispatch Async Event for DB Projection update (Fallback if Outbox is missing)
            if (!$this->outbox && isset($this->eventDispatcher)) {
                $this->eventDispatcher->dispatchAsync(
                    ScoreDeltaAppendedEvent::class,
                    new ScoreDeltaAppendedEvent($entityType, $entityId, $domain, $delta, $source)
                );
            }
        }

        return $success;
    }

    /**
     * Batch invalidation: Deletes the entire user score hash.
     */
    public function clearScoresCache(string $entityType, int $entityId): void
    {
        try {
            $redis = $this->cache->redis();
            if ($redis) {
                $cacheKey = "score:{$entityType}:{$entityId}"; // Fix Cache Key Corruption
                $redis->del($cacheKey);
            }
        } catch (\Throwable $e) {}
    }
}
