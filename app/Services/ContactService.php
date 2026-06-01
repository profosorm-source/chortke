<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\ContactMessage;
use App\Validators\Requests\ContactMessageRequest;

class ContactService
{
    private ContactMessage $contactMessageModel;
    private \Core\RateLimiter $rateLimiter;
    private \App\Services\CaptchaService $captchaService;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        ContactMessage $contactMessageModel,
        \Core\RateLimiter $rateLimiter,
        \App\Services\CaptchaService $captchaService
    ) {        $this->logger = $logger;

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
            throw new \Core\Exceptions\ValidationException(['captcha' => ['ارائه توکن و پاسخ کپچا الزامی است.']]);
        }

        if (!$this->verifyCaptcha($captchaToken, $captchaResponse, $ip, $data['challenge_answer'] ?? null)) {
            throw new \Core\Exceptions\SecurityException('تأییدیه امنیتی نامعتبر است.');
        }

        // 3. Fingerprint & IP Rate Limiting
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $fingerprint = hash('sha256', $ip . $ua . $lang);
        
        // 🛡️ MED-02: اضافه کردن Global Rate Limit برای مقاومت در برابر حملات توزیع شده
        $globalKey = "contact_form:global";
        if (!$this->rateLimiter->attempt($globalKey, 100, 3600)) {
            $this->logger->critical('contact_form.global_rate_limit_exceeded', ['ip' => $ip]);
            throw new \Core\Exceptions\RateLimitExceededException('سیستم موقتاً در دسترس نیست. لطفاً ساعتی دیگر تلاش کنید.');
        }

        $fpRateKey = "contact_form:fp:{$fingerprint}";
        if (!$this->rateLimiter->attempt($fpRateKey, 3, 3600)) {
            throw new \Core\Exceptions\RateLimitExceededException('تعداد پیام‌های ارسالی شما بیش از حد مجاز است. لطفاً ساعتی دیگر تلاش کنید.');
        }
        
        $strictIpKey = "contact_form:ip:{$ip}";
        if (!$this->rateLimiter->attempt($strictIpKey, 10, 3600)) {
            throw new \Core\Exceptions\RateLimitExceededException('ارسال پیام از این آدرس IP محدود شده است.');
        }

        // 4. Email Rate Limiting: 5 messages per day
        if (!empty($data['email'])) {
            $normalizedEmail = $this->normalizeContactEmail((string)$data['email']);
            $emailKey = "contact_form:email:" . hash('sha256', $normalizedEmail);
            if (!$this->rateLimiter->attempt($emailKey, 5, 86400)) {
                throw new \Core\Exceptions\RateLimitExceededException('این ایمیل امروز پیام‌های زیادی ارسال کرده است. لطفاً فردا تلاش کنید.');
            }
        }

        $request = new ContactMessageRequest($data);
        if (!$request->validate()) {
            throw new \Core\Exceptions\ValidationException($request->errors(), 'لطفاً تمام فیلدها را به درستی پر کنید.');
        }

        $data = $request->validated();

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

            $this->logger->info('contact.message.stored', [
                'email' => $email,
                'subject' => $subject,
            ]);

            return ['success' => true, 'message' => 'پیام شما با موفقیت ارسال شد. به زودی پاسخ خواهیم داد.', 'data' => []];
        } catch (\Exception $e) {
            $this->logger->error('contact.message.storage.failed', [
                'error' => $e->getMessage(),
            ]);

            throw new \Core\Exceptions\BusinessException('خطا در ارسال پیام. لطفاً دوباره تلاش کنید.');
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
