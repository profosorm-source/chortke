<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CriticalFeatureChangedEvent;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\LoggerInterface;

/**
 * AlertAdminOnCriticalFeatureChange
 *
 * هنگامی که یک Feature Flag حیاتی تغییر می‌کند:
 * ۱. Alert فوری به همه ادمین‌ها با اطلاعات کامل تغییر
 * ۲. لاگ در کانال critical برای monitoring
 *
 * این Listener جداگانه از LogFeatureFlagChange است تا SRP رعایت شود.
 */
class AlertAdminOnCriticalFeatureChange
{
    private NotificationServiceInterface $notificationService;
    private LoggerInterface $logger;
    public function __construct(
        NotificationServiceInterface $notificationService,
        LoggerInterface $logger
    ) {        $this->notificationService = $notificationService;
        $this->logger = $logger;
}

    public function handle(CriticalFeatureChangedEvent $event): void
    {
        $featureName = $event->featureName;
        $action      = $event->action;
        $changedBy   = $event->changedBy ?? 0;
        $changedAt   = $event->changedAt
            ? $event->changedAt->format('Y-m-d H:i:s')
            : date('Y-m-d H:i:s');

        $changesJson = !empty($event->changes)
            ? json_encode($event->changes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : '—';

        $title   = "⚠️ تغییر Feature Flag بحرانی: {$featureName}";
        $message = "فیچر «{$featureName}» با عملیات «{$action}» توسط کاربر #{$changedBy} در {$changedAt} تغییر کرد.\n\nجزئیات:\n{$changesJson}";

        try {
            $this->notificationService->sendToAdmins(
                'critical_feature_changed',
                $title,
                $message,
                [
                    'feature_name' => $featureName,
                    'action'       => $action,
                    'changed_by'   => $changedBy,
                    'changed_at'   => $changedAt,
                    'changes'      => $event->changes,
                ],
                'high'
            );
        } catch (\Throwable $e) {
            $this->logger->error('critical_feature.admin_alert_failed', [
                'feature' => $featureName,
                'error'   => $e->getMessage(),
            ]);
        }

        $this->logger->critical('critical_feature.changed_alert_sent', [
            'feature'    => $featureName,
            'action'     => $action,
            'changed_by' => $changedBy,
            'changed_at' => $changedAt,
        ]);
    }
}
