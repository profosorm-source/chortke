<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AuditRecordedEvent;
use App\Models\AuditTrail as AuditTrailModel;
use App\Contracts\LoggerInterface;

class PersistAuditRecordListener
{
    private AuditTrailModel $model;
    private LoggerInterface $logger;
    public function __construct(
        AuditTrailModel $model,
        LoggerInterface $logger
    ) {        $this->model = $model;
        $this->logger = $logger;
}

    public function handle(AuditRecordedEvent $event): void
    {
        try {
            $this->model->createEntry([
                'request_id' => $_SERVER['REQUEST_ID'] ?? null,
                'event' => $this->sanitizeEvent($event->eventName),
                'user_id' => $event->userId,
                'actor_id' => $event->actorId ?? null,
                'context' => json_encode($this->sanitizeContext($event->context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('persist_audit_record.failed', [
                'event' => $event->eventName,
                'user_id' => $event->userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function sanitizeEvent(string $event): string
    {
        return preg_replace('/[^a-z0-9_\.]/i', '_', $event);
    }

    private function sanitizeContext(array $context): array
    {
        // basic masking of sensitive keys
        $sensitive = ['password','pwd','token','secret','ssn','cvv','card','pan','key'];
        $out = [];
        foreach ($context as $k => $v) {
            $isSensitive = false;
            foreach ($sensitive as $p) {
                if (stripos((string)$k, $p) !== false) { $isSensitive = true; break; }
            }
            $out[$k] = $isSensitive ? '********' : $v;
        }
        return $out;
    }
}
