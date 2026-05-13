<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\EmailService;
use App\Services\RedisEmailQueueService;

/**
 * SendEmailJob — ارسال غیرمسدودکننده و پس‌زمینه ایمیل‌های سیستم چرتکه به صورت آنی
 */
class SendEmailJob
{
    private EmailService $emailService;
    private RedisEmailQueueService $redisQueue;

    public function __construct(EmailService $emailService, RedisEmailQueueService $redisQueue)
    {
        $this->emailService = $emailService;
        $this->redisQueue = $redisQueue;
    }

    /**
     * اجرای تسک ارسال ایمیل پس‌زمینه
     */
    public function handle(array $data): void
    {
        $emailId  = isset($data['email_id']) ? (string)$data['email_id'] : null;
        $toEmail  = $data['to_email'] ?? '';
        $toName   = $data['to_name'] ?? '';
        $subject  = $data['subject'] ?? '';
        $bodyHtml = $data['body_html'] ?? '';

        if (!$toEmail || !$subject || !$bodyHtml) {
            return;
        }

        // ارسال واقعی ایمیل از طریق SMTP
        $sent = $this->emailService->sendDirect($toEmail, $toName, $subject, $bodyHtml);

        if ($emailId) {
            if ($sent) {
                $this->redisQueue->markAsSent($emailId);
            } else {
                $this->redisQueue->markAsFailed($emailId, 'SMTP send failed via SendEmailJob background queue');
            }
        }
    }
}
