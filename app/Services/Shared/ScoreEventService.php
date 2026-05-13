<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\Score;
use App\Contracts\LoggerInterface;

class ScoreEventService extends \App\Services\BaseService
{
    public function __construct(
        private Score $scoreModel,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function addEvent(int $entityId, string $entityType, string $domain, float $delta, string $source, array $meta = []): bool
    {
        return $this->scoreModel->addEvent([
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'domain' => $domain,
            'delta' => $delta,
            'source' => $source,
            'meta' => $meta
        ]);
    }

    public function createEvent(int $userId, string $domain, string $source, float $delta, array $meta = []): bool
    {
        return $this->scoreModel->createEvent($userId, $domain, $source, $delta, $meta);
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
