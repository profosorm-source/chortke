<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * Transactional outbox writer.
 *
 * این سرویس فقط event را در همان transaction دیتابیس ثبت می‌کند. انتشار واقعی در
 * OutboxPublisher انجام می‌شود تا transaction مالی به queue/notification وابسته نباشد.
 */
class OutboxService extends BaseService
{
    public function __construct(
        private Database $db,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function record(
        string $aggregateType,
        string|int $aggregateId,
        string $eventType,
        array $payload = [],
        ?string $availableAt = null
    ): bool {
        $aggregateType = $this->sanitizeToken($aggregateType, 80);
        $eventType = $this->sanitizeToken($eventType, 120);
        $aggregateId = mb_substr((string)$aggregateId, 0, 128);

        if ($aggregateType === '' || $eventType === '' || $aggregateId === '') {
            throw new \InvalidArgumentException('Invalid outbox event identity.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO outbox_events
             (aggregate_type, aggregate_id, event_type, payload, status, attempts, available_at, created_at)
             VALUES (?, ?, ?, ?, 'pending', 0, ?, NOW())"
        );

        return $stmt->execute([
            $aggregateType,
            $aggregateId,
            $eventType,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $availableAt ?? date('Y-m-d H:i:s'),
        ]);
    }

    private function sanitizeToken(string $value, int $max): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.:-]/', '_', trim($value)) ?? '';
        return mb_substr($value, 0, $max);
    }
}
