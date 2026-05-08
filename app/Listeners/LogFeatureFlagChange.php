<?php

namespace App\Listeners;

use App\Events\FeatureFlagChanged;
use Core\Database;
use Core\Logger;

/**
 * Listener برای ذخیره تاریخچه تغییرات Feature Flags
 */
class LogFeatureFlagChange
{
    private Database $db;
    private Logger $logger;
    
    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
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
            
        } catch (\Exception $e) {
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
     * ذخیره در جدول تاریخچه
     */
    private function saveToHistory(FeatureFlagChanged $event): void
    {
        $changes = $event->getChanges();
        
        foreach ($changes as $field => $change) {
            $sql = "INSERT INTO feature_flag_history 
                    (feature_name, field_changed, old_value, new_value, changed_by, changed_at, action)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $this->db->query($sql, [
                $event->featureName,
                $field,
                json_encode($change['old']),
                json_encode($change['new']),
                $event->changedBy,
                $event->changedAt->format('Y-m-d H:i:s'),
                $event->action,
            ]);
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
        // لیست فیچرهای Critical
        $criticalFeatures = [
            'payment_gateway',
            'user_registration',
            'crypto_wallet',
            'withdrawal_system',
        ];
        
        if (!in_array($event->featureName, $criticalFeatures)) {
            return;
        }
        
        // TODO: ارسال Notification به ادمین‌های سیستم
        // می‌تونه از طریق Telegram Bot، Email یا SMS باشه
        
        $this->logger->critical('feature_flag.critical_change', [
            'channel' => 'feature_flag',
            'feature' => $event->featureName,
            'action' => $event->action,
            'message' => "یک فیچر Critical تغییر کرد: {$event->featureName}",
        ]);
    }
}
