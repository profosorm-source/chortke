<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Job;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\SmsNotificationService;
use App\Contracts\LoggerInterface;

/**
 * ProcessNotificationJob
 * 
 * این کلاس وظیفه ارسال پیام‌ها به APIهای خارجی (FCM, SMS, Email)
 * را در پس‌زمینه بر عهده دارد تا از کندی و Deadlock در سیستم جلوگیری شود.
 */
class ProcessNotificationJob extends Job
{
    private NotificationDispatcher $dispatcher;
    private LoggerInterface $logger;
    private ?SmsNotificationService $smsService;
    public function __construct(
        NotificationDispatcher $dispatcher,
        LoggerInterface $logger,
        ?SmsNotificationService $smsService = null
    ) {        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->smsService = $smsService;
}

    public function handle(array $payload): void
    {
        $channel = $payload['channel'] ?? null;
        $userIds = $payload['user_ids'] ?? [];
        
        if (empty($userIds) || !$channel) {
            return;
        }

        $cache = \Core\Cache::getInstance();
        $cbKey = "circuit_breaker:notif_{$channel}";
        
        if ($cache->get("{$cbKey}:open")) {
            $this->logger->warning('notif.circuit_breaker_open', ['channel' => $channel]);
            // Throw exception to let the queue worker release/delay the job
            throw new \RuntimeException("Circuit breaker is OPEN for {$channel}. Deferring execution.");
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
            
            // Success -> Reset errors
            $cache->forget("{$cbKey}:errors");

        } catch (\Throwable $e) {
            // Failure -> Increment errors
            $errors = $cache->increment("{$cbKey}:errors", 1, 60);
            if ($errors >= 30) {
                // Trip the breaker for 5 minutes
                $cache->put("{$cbKey}:open", true, 300);
            }

            $this->logger->error('job.process_notification_failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
                'consecutive_errors' => $errors
            ]);
            
            throw $e;
        }
    }
}
