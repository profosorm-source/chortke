<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FraudScoreUpdatedEvent;
use App\Contracts\LoggerInterface;
use Core\Database;

class ProcessFraudAlert
{
    private LoggerInterface $logger;
    private Database $db;
    public function __construct(
        LoggerInterface $logger,
        Database $db
    ) {        $this->logger = $logger;
        $this->db = $db;
}

    public function handle(FraudScoreUpdatedEvent $event): void
    {
        try {
            // Ignore normal scores to reduce noise
            if ($event->score < 75) {
                return;
            }

            // 🚨 1. Log massive security threat to system loggers asynchronously
            $severity = $event->score >= 90 ? 'CRITICAL' : 'WARNING';
            $this->logger->critical("anti_fraud.high_risk_detected", [
                'user_id' => $event->userId,
                'score' => $event->score,
                'severity' => $severity,
                'message' => "User #{$event->userId} exceeded safe fraud score thresholds with value {$event->score}"
            ]);

            // 🚨 2. Write to system_notifications table for dashboard alerts (heavy I/O decoupled!)
            $this->db->query(
                "INSERT INTO notifications (user_id, type, message, data, created_at) VALUES (?, ?, ?, ?, NOW())",
                [
                    $event->userId,
                    'high_fraud_alert',
                    "امتیاز تقلب کاربر به مرز بحرانی {$event->score} رسید.",
                    json_encode([
                        'score' => $event->score,
                        'action_required' => true,
                        'automated_trigger' => true
                    ])
                ]
            );

        } catch (\Throwable $e) {
            $this->logger->error('listener.process_fraud_alert.failed', [
                'user_id' => $event->userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
