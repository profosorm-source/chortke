<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Settings\AppSettings;

use App\Contracts\LoggerInterface;
use App\Contracts\EmailServiceInterface;
use App\Models\EmailQueue;
use App\Models\NotificationPreference;
use App\Models\Setting;
use App\Models\User;
use Core\Queue;

/**
 * EmailService — سرویس مرکزی ارسال ایمیل
 *
 * ─── استاندارد ارسال ───────────────────────────────────────────────────────
 *  • ایمیل‌های حیاتی  (تأیید حساب، بازیابی رمز):  sendDirect()  → فوری SMTP
 *  • ایمیل‌های عادی   (خوش‌آمد، برداشت، قرعه):    enqueue()     → صف + cron
 *
 * ─── اولویت تنظیمات SMTP (DB → ENV → Default) ─────────────────────────────
 *  1. جدول system_settings (پنل ادمین) — بالاترین اولویت
 *  2. فایل .env  — پشتیبان
 *  3. مقدار پیش‌فرض — آخرین راه‌حل
 */
class EmailService implements EmailServiceInterface
{
    private User                   $userModel;
    private AppSettings         $settingService;
    private EmailQueue             $emailQueue;
    private NotificationPreference $prefModel;
    private Queue                  $queue;

    private RedisEmailQueueService $redisQueue;
    
    private string $smtpHost;
    private int    $smtpPort;
    private string $smtpUsername;
    private string $smtpPassword;
    private string $smtpEncryption;
    private string $fromEmail;
    private string $fromName;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        EmailQueue             $emailQueue,
        NotificationPreference $prefModel,
        AppSettings         $settingService,
        User                   $userModel,
        Queue                  $queue,
        RedisEmailQueueService $redisQueue
    ) {        $this->logger = $logger;

                $this->emailQueue   = $emailQueue;
        $this->prefModel    = $prefModel;
        $this->settingService = $settingService;
        $this->userModel    = $userModel;
        $this->queue        = $queue;
        $this->redisQueue   = $redisQueue;

        $this->loadSmtpSettings();
    }

    // =========================================================================
    // بارگذاری تنظیمات: DB → ENV → Default
    // =========================================================================

    private function loadSmtpSettings(): void
    {
        $s = $this->settingService;

        $this->smtpHost       = (string) $this->resolve($s->get('smtp_host'),       config('mail.host',         '127.0.0.1'));
        $this->smtpPort       = (int) $this->resolve($s->get('smtp_port'), config('mail.port',         1025));
        $this->smtpUsername   = (string) $this->resolve($s->get('smtp_username'),   config('mail.username',     ''));
        $this->smtpPassword   = (string) $this->resolve($s->get('smtp_password'),   config('mail.password',     ''));
        $this->smtpEncryption = (string) $this->resolve($s->get('smtp_encryption'), config('mail.encryption',   ''));
        $this->fromEmail      = (string) $this->resolve($s->get('smtp_from_email'), config('mail.from.address', 'noreply@example.com'));
        $this->fromName       = (string) $this->resolve($s->get('smtp_from_name'),  config('mail.from.name',    'سایت'));

        if (!filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            $this->fromEmail = 'noreply@example.com';
        }
    }

    /** اگه DB مقدار داشت همون، وگرنه ENV */
    private function resolve(mixed $dbValue, mixed $envValue): mixed
    {
        return ($dbValue !== null && $dbValue !== '') ? $dbValue : ($envValue ?? '');
    }

    // =========================================================================
    // API عمومی — ارسال مستقیم (حیاتی)
    // =========================================================================

    /**
     * ارسال فوری SMTP — برای ایمیل‌های حیاتی
     * (تأیید ایمیل، بازیابی رمز عبور)
     */
    public function sendDirect(string $toEmail, string $toName, string $subject, string $bodyHtml): bool
    {
        // Input validation
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->error('email.send_direct.invalid_email', ['email' => $toEmail]);
            throw new \InvalidArgumentException('Invalid email address: ' . $toEmail);
        }

        if (empty($toName) || strlen($toName) > 255) {
            throw new \InvalidArgumentException('Invalid recipient name: must be non-empty and max 255 chars');
        }

        if (empty($subject) || strlen($subject) > 255) {
            throw new \InvalidArgumentException('Invalid subject: must be non-empty and max 255 chars');
        }

        if (empty($bodyHtml) || strlen($bodyHtml) > 50000) {
            throw new \InvalidArgumentException('Invalid body: must be non-empty and max 50000 chars');
        }

        $bodyHtml = $this->sanitizeEmailBody($bodyHtml);

        try {
            return $this->sendViaSMTP($toEmail, $toName, $subject, $bodyHtml);
        } catch (\Exception $e) {
            $this->logger->error('email.send_direct.failed', ['to' => $toEmail, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** alias backward-compat */
    public function sendNow(string $toEmail, string $toName, string $subject, string $bodyHtml): bool
    {
        return $this->sendDirect($toEmail, $toName, $subject, $bodyHtml);
    }

    // =========================================================================
    // API عمومی — صف ایمیل (غیرحیاتی)
    // =========================================================================

    /**
     * افزودن به صف — برای ایمیل‌های غیرحیاتی
     * (خوش‌آمدگویی، تأیید برداشت، قرعه‌کشی)
     */
    public function enqueue(
        int     $userId,
        string  $subject,
        string  $bodyHtml,
        ?string $bodyText    = null,
        string  $priority    = 'normal',
        ?string $scheduledAt = null
    ): string|int|null {
        // Input validation
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user ID: must be positive');
        }

        if (empty($subject) || strlen($subject) > 255) {
            throw new \InvalidArgumentException('Invalid subject: must be non-empty and max 255 chars');
        }

        if (empty($bodyHtml) || strlen($bodyHtml) > 50000) {
            throw new \InvalidArgumentException('Invalid body: must be non-empty and max 50000 chars');
        }

        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            throw new \InvalidArgumentException('Invalid priority: must be low, normal, or high');
        }

        if ($scheduledAt !== null && !strtotime($scheduledAt)) {
            throw new \InvalidArgumentException('Invalid scheduled_at date format');
        }

        try {
            $user = $this->userModel->find($userId);
            if (!$user || !$user->email) {
                $this->logger->warning('email.enqueue.no_email', ['user_id' => $userId]);
                return null;
            }

            if (!$this->prefModel->isEmailEnabled($userId, 'system')) {
                $this->logger->info('email.enqueue.pref_skip', ['user_id' => $userId]);
                return null;
            }

            // اضافه کردن به صف با استفاده از RedisEmailQueueService با قابلیت Fallback اتوماتیک
            $bodyHtml = $this->sanitizeEmailBody($bodyHtml);

            $emailId = $this->redisQueue->push([
                'user_id'      => $userId,
                'to'           => $user->email,
                'subject'      => $subject,
                'body'         => $bodyHtml,
                'priority'     => $priority,
                'scheduled_at' => $scheduledAt ? strtotime($scheduledAt) : time(),
                'variables'    => [],
            ]);

            if (!$emailId) {
                return null;
            }

            $this->logger->info('email.enqueue.added', ['email_id' => $emailId, 'user_id' => $userId]);

            // انتقال ارسال ایمیل به صف عمومی سیستم جهت ارسال غیرمسدودکننده و آنی (فقط برای ایمیل‌های بدون زمان‌بندی)
            if (!$scheduledAt) {
                $this->queue->push(\App\Jobs\SendEmailJob::class, [
                    'email_id'  => $emailId,
                    'to_email'  => $user->email,
                    'to_name'   => $user->full_name ?? $user->email,
                    'subject'   => $subject,
                    'body_html' => $bodyHtml,
                ]);
            }

            return $emailId;

        } catch (\Exception $e) {
            $this->logger->error('email.enqueue.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** alias backward-compat */
    public function queue(
        int $userId, string $subject, string $bodyHtml,
        ?string $bodyText = null, string $priority = 'normal', ?string $scheduledAt = null
    ): string|int|null {
        return $this->enqueue($userId, $subject, $bodyHtml, $bodyText, $priority, $scheduledAt);
    }

    // =========================================================================
    // پردازش صف — فراخوانی از cron
    // =========================================================================

    public function processQueue(int $batchSize = 10): array
    {
        $pendingEmails = $this->redisQueue->pop($batchSize);
        $stats = ['total' => count($pendingEmails), 'sent' => 0, 'failed' => 0];

        foreach ($pendingEmails as $email) {
            // تبدیل دیتای ایمیل (چه از Redis و چه از DB Fallback آرایه هستند)
            $email = (array)$email;
            $id = (string)($email['id'] ?? $email['email_id'] ?? 'db_' . ($email['id'] ?? '')); 
            $toEmail = $email['to'] ?? $email['to_email'] ?? '';
            $toName = $email['to_name'] ?? $toEmail;
            $subject = $email['subject'] ?? '';
            $body = $email['body'] ?? $email['body_html'] ?? '';

            if (empty($toEmail) || empty($subject) || empty($body)) {
                $stats['failed']++;
                continue;
            }

            $sent = $this->sendViaSMTP($toEmail, $toName, $subject, $body);

            if ($sent) {
                $this->redisQueue->markAsSent($id);
                $stats['sent']++;
            } else {
                $this->redisQueue->markAsFailed($id, 'SMTP send failed');
                $stats['failed']++;
            }

            usleep(300_000); // 0.3s فاصله ضد-spam
        }

        $this->logger->info('email.queue.processed', $stats);
        return $stats;
    }

    // =========================================================================
    // قالب‌های آماده
    // =========================================================================

    /**
     * ایمیل تأیید حساب — حیاتی → sendDirect
     */
    public function sendVerificationEmail(int $userId, string $token): bool
    {
        $user = $this->userModel->find($userId);
        if (!$user) return false;

        // کد ۶ رقمی = ۶ کاراکتر اول token (بدون نیاز به ستون جداگانه)
        $verifyCode = strtoupper(substr($token, 0, 6));

        $body = $this->getEmailTemplate('verification', [
            'name'        => $user->full_name ?? $user->email,
            'verify_url'  => url('/email/verify?token=' . $token),
            'verify_code' => $verifyCode,
        ]);

        return $this->sendDirect(
            $user->email,
            $user->full_name ?? $user->email,
            'تأیید ایمیل حساب کاربری',
            $body
        );
    }

    /**
     * ایمیل تأیید KYC — غیرحیاتی → enqueue
     */
    public function sendKYCApprovedEmail(int $userId): string|int|null
    {
        $user = $this->userModel->find($userId);
        if (!$user) return null;

        $body = $this->getEmailTemplate('kyc-approved', [
            'name'    => $user->full_name ?? $user->email,
            'kyc_url' => url('/kyc'),
        ]);

        return $this->enqueue($userId, '✅ مدارک شما تأیید شد', $body, null, 'high');
    }

    /**
     * ایمیل رد KYC — غیرحیاتی → enqueue
     */
    public function sendKYCRejectedEmail(int $userId, string $reason = ''): string|int|null
    {
        $user = $this->userModel->find($userId);
        if (!$user) return null;

        $body = $this->getEmailTemplate('kyc-rejected', [
            'name'    => $user->full_name ?? $user->email,
            'reason'  => $reason,
            'kyc_url' => url('/kyc'),
        ]);

        return $this->enqueue($userId, '❌ مدارک شما رد شد', $body, null, 'high');
    }

    /**
     * ایمیل پاسخ تیکت — غیرحیاتی → enqueue
     */
    public function sendTicketReplyEmail(int $userId, int $ticketId, string $subject, string $replyText): string|int|null
    {
        $user = $this->userModel->find($userId);
        if (!$user) return null;

        $body = $this->getEmailTemplate('ticket-reply', [
            'name'           => $user->full_name ?? $user->email,
            'ticket_id'      => $ticketId,
            'ticket_subject' => $subject,
            'reply_text'     => $replyText,
            'ticket_url'     => url('/tickets/' . $ticketId),
        ]);

        return $this->enqueue($userId, 'پاسخ به تیکت #' . $ticketId, $body, null, 'high');
    }

    /**
     * ایمیل تأیید واریز — غیرحیاتی → enqueue
     */
    public function sendDepositConfirmedEmail(int $userId, float $amount, string $currency, string $method = '', string $reference = ''): string|int|null
    {
        $user = $this->userModel->find($userId);
        if (!$user) return null;

        $body = $this->getEmailTemplate('deposit-confirmed', [
            'name'       => $user->full_name ?? $user->email,
            'amount'     => \Core\ValueObjects\Money::fromString((string)($amount))->format(),
            'currency'   => $currency,
            'method'     => $method,
            'reference'  => $reference,
            'date'       => to_jalali(date('Y-m-d H:i:s')),
            'wallet_url' => url('/wallet'),
        ]);

        return $this->enqueue($userId, '✅ واریز تأیید شد', $body, null, 'high');
    }

    /**
     * ایمیل بازیابی رمز — حیاتی → sendDirect
     * @return bool
     */
    public function sendPasswordResetEmail(int $userId, string $token): bool
    {
        $user = $this->userModel->find($userId);
        if (!$user) return false;

        $body = $this->getEmailTemplate('password-reset', [
            'name'       => $user->full_name ?? $user->email,
            'reset_url'  => url('/reset-password?token=' . $token),
            'expires_in' => '1 ساعت',
        ]);

        return $this->sendDirect($user->email, $user->full_name ?? $user->email, 'بازیابی رمز عبور', $body);
    }

    /**
     * ایمیل خوش‌آمدگویی — غیرحیاتی → enqueue
     * @return string|int|null
     */
    public function sendWelcomeEmail(int $userId): string|int|null
    {
        $user = $this->userModel->find($userId);
        if (!$user) return null;

        $body = $this->getEmailTemplate('welcome', [
            'name'          => $user->full_name ?? $user->email,
            'email'         => $user->email,
            'dashboard_url' => url('/dashboard'),
        ]);

        return $this->enqueue($userId, 'خوش آمدید به چرتکه', $body, null, 'high');
    }

    /**
     * ایمیل تأیید برداشت — غیرحیاتی → enqueue
     * @return string|int|null
     */
    public function sendWithdrawalConfirmation(int $userId, float $amount, string $currency): string|int|null
    {
        $body = $this->getEmailTemplate('withdrawal-approved', [
            'amount'     => \Core\ValueObjects\Money::fromString((string)($amount))->format(),
            'currency'   => $currency,
            'date'       => to_jalali(date('Y-m-d H:i:s')),
            'wallet_url' => url('/wallet'),
        ]);

        return $this->enqueue($userId, 'تأیید برداشت', $body, null, 'high');
    }

    /**
     * ایمیل برنده قرعه‌کشی — غیرحیاتی → enqueue
     * @return string|int|null
     */
    public function sendLotteryWinnerEmail(int $userId, float $prize): string|int|null
    {
        $body = $this->getEmailTemplate('lottery-winner', [
            'prize'      => \Core\ValueObjects\Money::fromString((string)($prize))->format(),
            'date'       => to_jalali(date('Y-m-d H:i:s')),
            'wallet_url' => url('/wallet'),
        ]);

        return $this->enqueue($userId, '🎉 تبریک! شما برنده شدید!', $body, null, 'urgent');
    }

    // =========================================================================
    // ارسال واقعی SMTP — internal
    // =========================================================================

    private function sendViaSMTP(string $toEmail, string $toName, string $subject, string $bodyHtml): bool
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host     = $this->smtpHost;
            $mail->Port     = $this->smtpPort;
            $mail->CharSet  = 'UTF-8';
            $mail->SMTPDebug = 0;
            $mail->Timeout  = 3; // 🚀 UPG: Decreased timeout to 3 seconds to avoid blocking loops
            $mail->SMTPKeepAlive = false;

            $isProd = (config('app.env', 'production') === 'production');
            if (!empty($this->smtpEncryption)) {
                $mail->SMTPSecure = $this->smtpEncryption;
                $mail->SMTPAuth   = true;
                $mail->Username   = $this->smtpUsername;
                $mail->Password   = $this->smtpPassword;
            } elseif ($isProd) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAuth   = !empty($this->smtpUsername);
                if ($mail->SMTPAuth) {
                    $mail->Username = $this->smtpUsername;
                    $mail->Password = $this->smtpPassword;
                }
            } else {
                // بدون TLS/SSL — MailHog / Mailtrap لوکال
                $mail->SMTPAuth = !empty($this->smtpUsername);
                if ($mail->SMTPAuth) {
                    $mail->Username = $this->smtpUsername;
                    $mail->Password = $this->smtpPassword;
                }
            }

            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => $isProd,
                    'verify_peer_name'  => $isProd,
                    'allow_self_signed' => !$isProd,
                ],
            ];

            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml));

            $mail->send();

            $this->logger->info('email.smtp.sent', [
                'to'   => $toEmail,
                'subj' => $subject,
                'host' => $this->smtpHost . ':' . $this->smtpPort,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('email.smtp.failed', [
                'channel' => 'email',
                'to' => $toEmail,
                'error' => $e->getMessage(),
                'host' => $this->smtpHost . ':' . $this->smtpPort,
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * پاکسازی محتوای ایمیل از اسکریپت‌ها و event handler های مخرب
     */
    private function sanitizeEmailBody(string $bodyHtml): string
    {
        $bodyHtml = preg_replace('#<script.*?</script>#is', '', $bodyHtml);
        $bodyHtml = preg_replace('#href\s*=\s*["\']\s*javascript:[^"\']*["\']#i', 'href="#"', $bodyHtml);
        $bodyHtml = preg_replace('#on[a-z]+\s*=\s*["\"][^"\"]*["\"]#i', '', $bodyHtml);
        $bodyHtml = preg_replace('#on[a-z]+\s*=\s*[^ >]+#i', '', $bodyHtml);
        return $bodyHtml;
    }

    // =========================================================================
    // قالب‌بندی ایمیل
    // =========================================================================

    private function getEmailTemplate(string $template, array $vars = []): string
    {
        $templatePath = __DIR__ . '/../../views/emails/' . $template . '.php';

        // 🔒 Secure Fix: XSS Protection on Dynamic Email Content from Database Source
        $safeVars = array_map(fn($v) => is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v, $vars);

        if (!file_exists($templatePath)) {
            return $this->getDefaultTemplate($safeVars);
        }

        // کپسوله‌سازی فرآیند اجرای قالب در یک تابع استاتیک منزوی جهت پیشگیری کامل از Variable Injection
        $render = static function (string $__templatePath, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__templatePath;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return ob_get_clean();
        };

        try {
            return $render($templatePath, $safeVars);
        } catch (\Throwable $e) {
            $this->logger->error('email.template_render.failed', [
                'template' => $template,
                'error' => $e->getMessage()
            ]);
            return $this->getDefaultTemplate($safeVars);
        }
    }

    private function getDefaultTemplate(array $vars): string
    {
        $siteName    = config('app.name', 'چرتکه');
        $siteUrl     = config('app.url', 'http://localhost');
        $bodyContent = $vars['content'] ?? 'محتوای ایمیل';

        return <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body{font-family:Tahoma,'Segoe UI',sans-serif;background:#f5f7fa;margin:0;padding:20px}
        .c{max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}
        .h{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:30px;text-align:center}
        .b{padding:30px;line-height:1.8}
        .f{background:#f8f9fa;padding:20px;text-align:center;font-size:12px;color:#666}
        .btn{display:inline-block;padding:12px 30px;background:#4fc3f7;color:#fff;text-decoration:none;border-radius:5px;margin:20px 0}
    </style>
</head>
<body>
    <div class="c">
        <div class="h"><h1>{$siteName}</h1></div>
        <div class="b">{$bodyContent}</div>
        <div class="f">
            <p>© 2025 {$siteName}. تمامی حقوق محفوظ است.</p>
            <p><a href="{$siteUrl}">وب‌سایت</a> | <a href="{$siteUrl}/help">راهنما</a> | <a href="{$siteUrl}/contact">تماس</a></p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    // =========================================================================
    // اطلاعات debug
    // =========================================================================

    public function getSmtpInfo(): array
    {
        if (!config('app.debug')) {
            throw new \LogicException('SMTP debug parameters are strictly withheld outside of explicit staging/debug environments.');
        }

        return [
            'host'       => $this->smtpHost,
            'port'       => $this->smtpPort,
            'encryption' => $this->smtpEncryption ?: 'none',
            'username'   => $this->smtpUsername ? '****' : '(empty)',
            'from_email' => $this->fromEmail,
            'from_name'  => $this->fromName,
        ];
    }

    public function searchEmails(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->emailQueue->query()
            ->select('email_queue.*', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'email_queue.user_id');

        if (!empty($q)) {
            $like = "%{$q}%";
            $query->where(function($sub) use ($like) {
                $sub->where('email_queue.subject', 'LIKE', $like)
                    ->orWhere('email_queue.to_email', 'LIKE', $like);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('email_queue.status', '=', e($filters['status'], ENT_QUOTES, 'UTF-8'));
        }

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('email_queue.created_at', 'DESC')
                                     ->limit($limit)->offset($offset)->get() ?? []
        ];
    }
}
