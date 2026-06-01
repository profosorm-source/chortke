<?php

declare(strict_types=1);

namespace App\Services\Sentry\Alerting;

use App\Models\SentryModel;
use Core\Logger;

/**
 * 📈 EscalationManager - مدیریت Escalation
 */
class EscalationManager
{
    private SentryModel $model;
    private Logger $logger;
    private AlertDispatcher $dispatcher;
    public function __construct(
        SentryModel $model,
        Logger $logger,
        AlertDispatcher $dispatcher
    ) {        $this->model = $model;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
}

    /**
     * 🔄 Process Escalations
     */
    public function processEscalations(): array
    {
        $escalated = [];
        $pendingAlerts = $this->model->getPendingEscalations();

        foreach ($pendingAlerts as $alert) {
            if ($this->shouldEscalate($alert)) {
                $this->escalateAlert($alert);
                $escalated[] = $alert;
            }
        }

        return $escalated;
    }

    private function shouldEscalate(object $alert): bool
    {
        // AL6: Prevent escalation if the alert is already acknowledged to eliminate spam
        if (!empty($alert->acknowledged_at) || 
            (isset($alert->status) && $alert->status === 'acknowledged') ||
            !empty($alert->is_acknowledged)) {
            return false;
        }

        $age = time() - strtotime((string)$alert->created_at);
        
        $escalationTime = match($alert->severity) {
            'critical' => 5 * 60,
            'high' => 15 * 60,
            'medium' => 60 * 60,
            'low' => 4 * 60 * 60,
            default => 60 * 60
        };

        return $age > $escalationTime;
    }

    private function escalateAlert(object $alert): void
    {
        try {
            $newSeverity = $this->getNextSeverity((string)$alert->severity);
            
            $this->model->escalateAlert((int)$alert->id, $newSeverity, (string)$alert->severity);

            $this->dispatcher->dispatch([
                'type' => 'escalation',
                'severity' => $newSeverity,
                'title' => "🚨 Escalated: {$alert->title}",
                'message' => $this->formatEscalationMessage($alert, $newSeverity),
                'metadata' => [
                    'original_severity' => $alert->severity,
                    'new_severity' => $newSeverity,
                    'alert_id' => $alert->id,
                    'age_minutes' => round((time() - strtotime((string)$alert->created_at)) / 60),
                ],
            ]);

            $this->logger->warning('Alert escalated', [
                'alert_id' => $alert->id,
                'from' => $alert->severity,
                'to' => $newSeverity,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Escalation failed', ['alert_id' => $alert->id, 'error' => $e->getMessage()]);
        }
    }

    private function getNextSeverity(string $current): string
    {
        return match($current) {
            'low' => 'medium',
            'medium' => 'high',
            'high' => 'critical',
            'critical' => 'critical',
            default => 'medium'
        };
    }

    private function formatEscalationMessage(object $alert, string $newSeverity): string
    {
        $age = round((time() - strtotime((string)$alert->created_at)) / 60);
        return sprintf(
            "⚠️ Alert escalated from %s to %s\n\nOriginal Alert: %s\nAge: %d minutes\nStatus: Unacknowledged\n\nPlease investigate immediately!",
            strtoupper((string)$alert->severity),
            strtoupper($newSeverity),
            $alert->message,
            $age
        );
    }

    public function acknowledgeAlert(int $alertId, int $userId, ?string $note = null): bool
    {
        try {
            $this->model->acknowledgeAlert($alertId, $userId, $note);
            $this->logger->info('Alert acknowledged', ['alert_id' => $alertId, 'user_id' => $userId]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Acknowledge failed', ['alert_id' => $alertId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function autoResolveAlerts(): int
    {
        try {
            $resolved = $this->model->autoResolveErrorAlerts();
            if ($resolved > 0) {
                $this->logger->info("Auto-resolved {$resolved} alerts");
            }
            return $resolved;
        } catch (\Throwable $e) {
            $this->logger->error('Auto-resolve failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    public function getStatistics(): array
    {
        $stats = $this->model->getEscalationStatistics();

        return [
            'total_alerts' => (int)($stats->total_alerts ?? 0),
            'acknowledged' => (int)($stats->acknowledged ?? 0),
            'escalated' => (int)($stats->escalated ?? 0),
            'avg_response_time_minutes' => round((float)($stats->avg_response_time ?? 0), 2),
        ];
    }
}
