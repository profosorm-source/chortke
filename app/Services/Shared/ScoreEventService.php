<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\Score;
use App\Contracts\LoggerInterface;
use App\Enums\ScoreDomain;

class ScoreEventService extends \App\Services\BaseService
{
    public function __construct(
        private Score $scoreModel,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * Section 8.2 — optional idempotency via $dedupKey.
     * Many score events are legitimately repeated (e.g. "+5 per task"),
     * so dedup is opt-in. Pass a stable dedupKey (e.g. "task:{id}:{userId}")
     * to prevent double-crediting from at-least-once callers/queues.
     */
    public function addEvent(
        int $entityId,
        string $entityType,
        string $domain,
        float $delta,
        string $source,
        array $meta = [],
        ?string $dedupKey = null
    ): bool {
        $domain = ScoreDomain::normalize($domain);

        $write = function () use ($entityId, $entityType, $domain, $delta, $source, $meta): array {
            $ok = $this->scoreModel->addEvent([
                'entity_id'   => $entityId,
                'entity_type' => $entityType,
                'domain'      => $domain,
                'delta'       => $delta,
                'source'      => $source,
                'meta'        => $meta,
            ]);

            if ($ok) {
                $this->logInfo('score_event.recorded', [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'domain'      => $domain,
                    'delta'       => $delta,
                    'source'      => $source,
                ]);
            }
            return ['ok' => (bool)$ok];
        };

        if ($dedupKey === null || $dedupKey === '') {
            return (bool)($write()['ok']);
        }

        $result = $this->idempotent(
            'score.add',
            $entityId,
            [
                'entity_type' => $entityType,
                'domain'      => $domain,
                'source'      => $source,
                'dedup'       => $dedupKey,
            ],
            $write
        );
        return (bool)($result['ok'] ?? false);
    }

    /**
     * Renamed to recordEvent to avoid confusion with ScoreService delegates.
     * Section 8.2 — optional idempotency via $dedupKey (see addEvent()).
     * 
     * ✅ TRANSACTION BOUNDARY: This method is called WITHIN transaction
     * All score event recording happens atomically with the caller's transaction
     */
    public function recordEvent(
        int $userId,
        string $domain,
        string $source,
        float $delta,
        array $meta = [],
        ?string $dedupKey = null
    ): bool {
        $domain = ScoreDomain::normalize($domain);

        $write = function () use ($userId, $domain, $source, $delta, $meta): array {
            $ok = $this->scoreModel->createEvent($userId, $domain, $source, $delta, $meta);
            if ($ok) {
                $this->logInfo('score_event.user_recorded', [
                    'user_id' => $userId,
                    'domain'  => $domain,
                    'delta'   => $delta,
                    'source'  => $source,
                    'in_transaction' => true,
                ]);
            }
            return ['ok' => (bool)$ok];
        };

        if ($dedupKey === null || $dedupKey === '') {
            return (bool)($write()['ok']);
        }

        $result = $this->idempotent(
            'score.record',
            $userId,
            [
                'domain' => $domain,
                'source' => $source,
                'dedup'  => $dedupKey,
            ],
            $write
        );
        return (bool)($result['ok'] ?? false);
    }

    public function getTotalScore(int $entityId, string $entityType, string $domain): float
    {
        return $this->scoreModel->getTotal($entityId, $entityType, $domain);
    }

    public function getRecentScoreEvents(int $userId, int $limit = 50): array
    {
        return $this->scoreModel->getRecentEvents($userId, $limit);
    }

    public function getEventsByUser(int $userId, ?string $domain = null, int $limit = 200): array
    {
        return $this->scoreModel->getEventsByUser($userId, $domain, $limit);
    }
}
