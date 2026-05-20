<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Database;
use Core\Queue;
use Core\EventDispatcher;

/**
 * Publishes pending outbox events to the async queue/event bus.
 */
class OutboxPublisher extends BaseService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private Database $db,
        private Queue $queue,
        private EventDispatcher $events,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function publishPending(int $limit = 50): array
    {
        if (!$this->tableExists()) {
            return ['published' => 0, 'failed' => 0, 'skipped' => 'outbox_events table missing'];
        }

        $limit = max(1, min(200, $limit));
        $published = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $event = $this->reserveOne();
            if (!$event) {
                break;
            }

            try {
                $this->publish($event);
                $this->markPublished((int)$event->id);
                $published++;
            } catch (\Throwable $e) {
                $failed++;
                $this->markFailedOrRetry($event, $e);
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }


    private function tableExists(): bool
    {
        try {
            return (bool)$this->db->fetchColumn('SHOW TABLES LIKE ?', ['outbox_events']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function reserveOne(): ?object
    {
        $this->db->beginTransaction();
        try {
            $event = $this->db->selectOne(
                "SELECT * FROM outbox_events
                 WHERE status IN ('pending','failed')
                   AND attempts < :max_attempts
                   AND available_at <= NOW()
                 ORDER BY created_at ASC
                 LIMIT 1 FOR UPDATE",
                ['max_attempts' => self::MAX_ATTEMPTS]
            );

            if (!$event) {
                $this->db->commit();
                return null;
            }

            $this->db->execute(
                "UPDATE outbox_events
                 SET status = 'processing', attempts = attempts + 1, updated_at = NOW()
                 WHERE id = ?",
                [(int)$event->id]
            );

            $event->attempts = (int)$event->attempts + 1;
            $this->db->commit();
            return $event;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function publish(object $event): void
    {
        $payload = json_decode((string)($event->payload ?? '{}'), true) ?: [];
        $eventType = (string)$event->event_type;

        // Direct notification intent, executed by publisher with retry/DLQ semantics.
        if (!empty($payload['notification']) && is_array($payload['notification'])) {
            $this->publishNotification($payload['notification']);
            return;
        }

        // If payload explicitly asks for a queue job, enqueue it idempotently.
        if (!empty($payload['job']) && is_string($payload['job'])) {
            $dedup = 'outbox:' . $event->id . ':' . $eventType;
            $this->queue->pushUnique($payload['job'], (array)($payload['data'] ?? []), $dedup, $payload['queue'] ?? null, 0, 86400);
            return;
        }

        // Default publication: enqueue framework event dispatcher branch.
        $this->queue->pushUnique(
            'dispatch_event',
            [
                'event_name' => $eventType,
                'event_data' => array_merge($payload, [
                    'aggregate_type' => $event->aggregate_type,
                    'aggregate_id' => $event->aggregate_id,
                    'outbox_id' => (int)$event->id,
                ]),
                'event_class' => \Core\GenericEvent::class,
            ],
            'outbox_event:' . $event->id,
            null,
            0,
            86400
        );
    }


    private function publishNotification(array $notification): void
    {
        $method = (string)($notification['method'] ?? 'send');
        $allowed = ['send', 'sendFromTemplate', 'depositSuccess', 'withdrawalApproved', 'withdrawalRejected', 'securityAlert', 'sendToAdmins'];
        if (!in_array($method, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported outbox notification method: ' . $method);
        }

        $service = \Core\Container::getInstance()->make(\App\Services\Notification\NotificationService::class);
        $args = (array)($notification['args'] ?? []);
        $service->{$method}(...$args);
    }

    private function markPublished(int $id): void
    {
        $this->db->execute(
            "UPDATE outbox_events
             SET status = 'published', published_at = NOW(), updated_at = NOW(), last_error = NULL
             WHERE id = ?",
            [$id]
        );
    }

    private function markFailedOrRetry(object $event, \Throwable $e): void
    {
        $attempts = (int)($event->attempts ?? 1);
        $delay = min(60 * (2 ** max(0, $attempts - 1)), 3600);
        $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

        $this->db->execute(
            "UPDATE outbox_events
             SET status = ?, last_error = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW()
             WHERE id = ?",
            [$status, mb_substr($e->getMessage(), 0, 2000), $delay, (int)$event->id]
        );

        $this->logger->warning('outbox.publish_failed', [
            'outbox_id' => $event->id ?? null,
            'event_type' => $event->event_type ?? null,
            'attempts' => $attempts,
            'status' => $status,
            'error' => $e->getMessage(),
        ]);
    }
}
