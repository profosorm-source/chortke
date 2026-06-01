<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\AuditTrail;
use Core\Database;

/**
 * Transactional outbox writer.
 *
 * این سرویس فقط event را در همان transaction دیتابیس ثبت می‌کند. انتشار واقعی در
 * OutboxPublisher انجام می‌شود تا transaction مالی به queue/notification وابسته نباشد.
 */
class OutboxService implements \App\Contracts\OutboxServiceInterface
{
    private AuditTrail $auditTrail;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        AuditTrail $auditTrail
    ) {        $this->db = $db;
        $this->logger = $logger;

        
        $this->auditTrail = $auditTrail;
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

        $res = $stmt->execute([
            $aggregateType,
            $aggregateId,
            $eventType,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $availableAt ?? date('Y-m-d H:i:s'),
        ]);

        if ($res) {
            try {
                $this->auditTrail->record(
                    'outbox.event.recorded',
                    null,
                    [
                        'aggregate_type' => $aggregateType,
                        'aggregate_id' => $aggregateId,
                        'event_type' => $eventType,
                        'payload' => $payload,
                        'available_at' => $availableAt ?? date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Throwable $e) {
                // Swallow audit recording errors to avoid breaking domain transaction
                $this->logger->warning('outbox.audit.record.failed', ['error' => $e->getMessage()]);
            }
        }

        return $res;
    }

    private function sanitizeToken(string $value, int $max): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.:-]/', '_', trim($value)) ?? '';
        return mb_substr($value, 0, $max);
    }
}
