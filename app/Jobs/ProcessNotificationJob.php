<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Job;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\SmsNotificationService;

/**
 * ProcessNotificationJob
 * 
 * این کلاس وظیفه ارسال پیام‌ها به APIهای خارجی (FCM, SMS, Email)
 * را در پس‌زمینه بر عهده دارد تا از کندی و Deadlock در سیستم جلوگیری شود.
 */
class ProcessNotificationJob extends Job
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private ?SmsNotificationService $smsService = null
    ) {}

    public function handle(array $payload): void
    {
        $channel = $payload['channel'] ?? null;
        $userIds = $payload['user_ids'] ?? [];
        
        if (empty($userIds) || !$channel) {
            return;
        }

        try {
            switch ($channel) {
                case 'fcm':
                    $this->dispatcher->dispatchBulk(
                        'fcm', 
                        $userIds, 
                        $payload['title'] ?? '', 
                        $payload['message'] ?? '', 
                        $payload['data'] ?? null, 
                        null, 
                        $payload['action_url'] ?? null
                    );
                    break;
    
                case 'sms':
                    if (count($userIds) === 1) {
                        $userId = $userIds[0];
                        $smsType = $payload['sms_type'] ?? 'generic';
                        
                        if ($smsType === 'security' && $this->smsService) {
                            $this->smsService->sendSecurityAlertToUser((int)$userId, $payload['message']);
                        } elseif ($smsType === 'withdrawal' && $this->smsService) {
                            $this->smsService->sendWithdrawalAlertToUser((int)$userId, (float)$payload['amount'], $payload['currency']);
                        } else {
                            $this->dispatcher->dispatch('sms', (int)$userId, $payload['title'] ?? '', $payload['message'] ?? '');
                        }
                    }
                    break;
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->error('job.process_notification_failed', [
                    'channel' => $channel,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
