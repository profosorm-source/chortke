<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\ContactMessage;

class ContactService extends \App\Services\BaseService
{
    private ContactMessage $contactMessageModel;
    private \Core\RateLimiter $rateLimiter;
    private \App\Services\CaptchaService $captchaService;

    public function __construct(
        ContactMessage $contactMessageModel,
        LoggerInterface $logger,
        \Core\RateLimiter $rateLimiter,
        \App\Services\CaptchaService $captchaService
    ) {
        parent::__construct($logger);
        $this->contactMessageModel = $contactMessageModel;
        $this->rateLimiter = $rateLimiter;
        $this->captchaService = $captchaService;
    }

    /**
     * ثبت پیام تماس
     */
    public function sendMessage(array $data): array
    {
        // Honeypot check moved to ContactController

        // 2. CAPTCHA Verification
        $captchaToken = $data['captcha_token'] ?? '';
        $captchaResponse = $data['captcha_response'] ?? '';
        $ip = get_client_ip();

        // 🛡️ HIGH-17: اعتبارسنجی قطعی فیلدهای توکن کپچا و پاسخ آن جهت ممانعت از ارسال هرزنامه توسط بات‌ها
        if (empty($captchaToken) || empty($captchaResponse)) {
            return $this->errorResponse('ارائه توکن و پاسخ کپچا الزامی است.', [], 422);
        }

        if (!$this->verifyCaptcha($captchaToken, $captchaResponse, $ip, $data['challenge_answer'] ?? null)) {
            return $this->errorResponse('تأییدیه امنیتی نامعتبر است.', [], 422);
        }

        // 3. Fingerprint & IP Rate Limiting
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $fingerprint = hash('sha256', $ip . $ua . $lang);
        
        $fpRateKey = "contact_form:fp:{$fingerprint}";
        if (!$this->rateLimiter->attempt($fpRateKey, 3, 3600)) {
            return $this->errorResponse('تعداد پیام‌های ارسالی شما بیش از حد مجاز است. لطفاً ساعتی دیگر تلاش کنید.', [], 429);
        }
        
        $strictIpKey = "contact_form:ip:{$ip}";
        if (!$this->rateLimiter->attempt($strictIpKey, 10, 3600)) {
            return $this->errorResponse('ارسال پیام از این آدرس IP محدود شده است.', [], 429);
        }

        // 4. Email Rate Limiting: 5 messages per day
        if (!empty($data['email'])) {
            $normalizedEmail = $this->normalizeContactEmail((string)$data['email']);
            $emailKey = "contact_form:email:" . hash('sha256', $normalizedEmail);
            if (!$this->rateLimiter->attempt($emailKey, 5, 86400)) {
                return $this->errorResponse('این ایمیل امروز پیام‌های زیادی ارسال کرده است. لطفاً فردا تلاش کنید.', [], 429);
            }
        }

        // Validation logic
        $errors = $this->validate($data);
        if (!empty($errors)) {
            return $this->errorResponse('لطفاً تمام فیلدها را به درستی پر کنید.', $errors, 422);
        }

        try {
            $name = htmlspecialchars(trim((string)$data['name']), ENT_QUOTES, 'UTF-8');
            $email = filter_var(trim((string)$data['email']), FILTER_SANITIZE_EMAIL);
            $subject = htmlspecialchars(trim((string)$data['subject']), ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars(trim((string)$data['message']), ENT_QUOTES, 'UTF-8');

            $this->contactMessageModel->createMessage([
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'message' => $message,
                'ip_address' => $ip,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->logInfo('contact.message.stored', [
                'email' => $email,
                'subject' => $subject,
            ]);

            return $this->successResponse('پیام شما با موفقیت ارسال شد. به زودی پاسخ خواهیم داد.');
        } catch (\Exception $e) {
            $this->logError('contact.message.storage.failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('خطا در ارسال پیام. لطفاً دوباره تلاش کنید.');
        }
    }

    private function verifyCaptcha(?string $token, ?string $response, string $ip, ?string $challengeAnswer = null): bool
    {
        if ($this->captchaService->isEnabled()) {
            if (empty($token) || empty($response)) {
                return false;
            }
            return $this->captchaService->verify($token, $response);
        }

        // 🛡️ HIGH-11: Ensure CAPTCHA is enforced in production
        if (function_exists('env') && env('APP_ENV') === 'production') {
            $this->logger->critical('security.captcha_disabled_in_production', [
                'ip' => $ip,
                'action' => 'contact_form_submission',
            ]);
            return false;
        }

        $this->logger->warning('security.captcha_disabled_contact_form', [
            'ip' => $ip,
            'action' => 'contact_form_submission',
        ]);

        // 🛡️ FAIL-06: Fallback to Math Challenge
        if (function_exists('session')) {
            $challenge = session()->get('math_challenge');
            if ($challenge && isset($challenge['answer'])) {
                if ((int)$challengeAnswer !== (int)$challenge['answer']) {
                    return false;
                }
            }
        }

        $fallbackKey = "contact_form:captcha_disabled:{$ip}";
        return $this->rateLimiter->attempt($fallbackKey, 2, 3600);
    }

    /**
     * اعتبارسنجی داده‌ها
     */
    private function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name']) || mb_strlen($data['name']) < 3) {
            $errors['name'] = 'نام باید حداقل ۳ کاراکتر باشد.';
        }

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'ایمیل معتبر الزامی است.';
        }

        if (empty($data['subject']) || mb_strlen($data['subject']) < 5) {
            $errors['subject'] = 'موضوع باید حداقل ۵ کاراکتر باشد.';
        }

        if (empty($data['message']) || mb_strlen($data['message']) < 10) {
            $errors['message'] = 'متن پیام باید حداقل ۱۰ کاراکتر باشد.';
        }

        if (!empty($data['message']) && mb_strlen($data['message']) > 5000) {
            $errors['message'] = 'متن پیام نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد.';
        }

        return $errors;
    }

    private function normalizeContactEmail(string $email): string
    {
        $email = trim($email);
        $email = strtolower($email);
        $email = trim($email, ". \t\n\r\f");

        if (str_contains($email, '@')) {
            [$local, $domain] = explode('@', $email, 2) + ['', ''];
            $local = preg_replace('/\+.*$/', '', $local);
            if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
                $local = str_replace('.', '', $local);
                $domain = 'gmail.com';
            }
            $email = sprintf('%s@%s', $local, $domain);
        }

        return $email;
    }
}
