<?php

declare(strict_types=1);

namespace App\Contracts;

interface OutboxServiceInterface
{
    /**
     * Records an event in the transactional outbox.
     *
     * @param string $aggregateType
     * @param string|int $aggregateId
     * @param string $eventType
     * @param array $payload
     * @param string|null $availableAt
     * @return bool
     */
    public function record(
        string $aggregateType,
        string|int $aggregateId,
        string $eventType,
        array $payload = [],
        ?string $availableAt = null
    ): bool;
}
