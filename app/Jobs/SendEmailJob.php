<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\EmailService;
use App\Models\EmailQueue;

/**
 * SendEmailJob — ارسال غیرمسدودکننده و پس‌زمینه ایمیل‌های سیستم چرتکه به صورت آنی
 */
class SendEmailJob
{
    private EmailService $emailService;
    private EmailQueue $emailQueue;

    public function __construct(EmailService $emailService, EmailQueue $emailQueue)
    {
        $this->emailService = $emailService;
        $this->emailQueue = $emailQueue;
    }

    /**
     * اجرای تسک ارسال ایمیل پس‌زمینه
     */
    public function handle(array $data): void
    {
        $emailId  = isset($data['email_id']) ? (int)$data['email_id'] : null;
        $toEmail  = $data['to_email'] ?? '';
        $toName   = $data['to_name'] ?? '';
        $subject  = $data['subject'] ?? '';
        $bodyHtml = $data['body_html'] ?? '';

        if (!$toEmail || !$subject || !$bodyHtml) {
            return;
        }

        if ($emailId) {
            $this->emailQueue->markAsSending($emailId);
        }

        // ارسال واقعی ایمیل از طریق SMTP
        $sent = $this->emailService->sendDirect($toEmail, $toName, $subject, $bodyHtml);

        if ($emailId) {
            if ($sent) {
                $this->emailQueue->markAsSent($emailId);
            } else {
                $this->emailQueue->markAsFailed($emailId, 'SMTP send failed via SendEmailJob background queue');
            }
        }
    }
}
