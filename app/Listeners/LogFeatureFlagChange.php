<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FeatureFlagChanged;
use Core\Database;
use App\Services\Notification\NotificationService;
use App\Contracts\LoggerInterface;

/**
 * Listener برای ذخیره تاریخچه تغییرات Feature Flags
 */
class LogFeatureFlagChange
{
    private Database $db;
    private LoggerInterface $logger;
    private NotificationService $notificationService;
    
    public function __construct(Database $db, LoggerInterface $logger, NotificationService $notificationService)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
    }
    
    /**
     * Handle the event
     */
    public function handle(FeatureFlagChanged $event): void
    {
        try {
            // ذخیره در جدول تاریخچه
            $this->saveToHistory($event);
            
            // لاگ کردن در سیستم Logging
            $this->logChange($event);
            
            // اگر فیچر مهمی تغییر کرده، Notification بفرست
            $this->sendNotificationIfCritical($event);
            
        } catch (\Throwable $e) {
            $this->logger->error('feature_flag.listener.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'event' => [
                    'feature' => $event->featureName,
                    'action' => $event->action,
                ],
            ]);
        }
    }
    
    /**
     * ذخیره در جدول تاریخچه به صورت Bulk و ایمن با Transaction
     */
    private function saveToHistory(FeatureFlagChanged $event): void
    {
        $changes = $event->getChanges();
        if (empty($changes)) {
            return;
        }

        try {
            $this->db->beginTransaction();
            
            $placeholders = [];
            $params = [];
            foreach ($changes as $field => $change) {
                $placeholders[] = "(?, ?, ?, ?, ?, ?, ?)";
                array_push($params,
                    $event->featureName,
                    $field,
                    json_encode($change['old']),
                    json_encode($change['new']),
                    $event->changedBy,
                    $event->changedAt->format('Y-m-d H:i:s'),
                    $event->action
                );
            }
            
            $sql = "INSERT INTO feature_flag_history 
                    (feature_name, field_changed, old_value, new_value, changed_by, changed_at, action)
                    VALUES " . implode(', ', $placeholders);
            
            $this->db->query($sql, $params);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * لاگ کردن تغییرات
     */
    private function logChange(FeatureFlagChanged $event): void
    {
        $logData = [
            'channel' => 'feature_flag',
            'feature' => $event->featureName,
            'action' => $event->action,
            'changed_by' => $event->changedBy,
            'changes' => $event->getChanges(),
        ];
        
        if ($event->wasEnabled()) {
            $this->logger->info('feature_flag.enabled', $logData);
        } elseif ($event->wasDisabled()) {
            $this->logger->warning('feature_flag.disabled', $logData);
        } else {
            $this->logger->info('feature_flag.' . $event->action, $logData);
        }
    }
    
    /**
     * ارسال Notification برای فیچرهای Critical
     */
    private function sendNotificationIfCritical(FeatureFlagChanged $event): void
    {
        // استخراج فیچرهای حساس از تنظیمات پویا
        $criticalFeatures = config('feature_flags.critical') ?? [
            'payment_gateway',
            'user_registration',
            'crypto_wallet',
            'withdrawal_system',
        ];
        
        if (!in_array($event->featureName, $criticalFeatures, true)) {
            return;
        }
        
        // ارسال Notification به ادمین‌های سیستم
        $this->notificationService->sendToAdmins('critical_feature_change', [
            'feature' => $event->featureName,
            'action' => $event->action,
        ]);
        
        $this->logger->critical('feature_flag.critical_change', [
            'channel' => 'feature_flag',
            'feature' => $event->featureName,
            'action' => $event->action,
            'message' => "یک فیچر Critical تغییر کرد: {$event->featureName}",
        ]);
    }
}
