<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\ContactMessage;

class ContactService extends \App\Services\BaseService
{
    private ContactMessage $contactMessageModel;

    public function __construct(ContactMessage $contactMessageModel, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->contactMessageModel = $contactMessageModel;
    }

    /**
     * ثبت پیام تماس
     */
    public function sendMessage(array $data): array
    {
        // 1. Honeypot check
        if (!empty($data['website'])) {
            return $this->successResponse('پیام شما با موفقیت ارسال شد. به زودی پاسخ خواهیم داد.'); // Fake success for bots
        }

        // 2. CAPTCHA Verification
        $captchaToken = $data['captcha_token'] ?? '';
        $captchaResponse = $data['captcha_response'] ?? '';
        if (!$this->verifyCaptcha($captchaToken, $captchaResponse)) {
            return $this->errorResponse('لطفاً کپچا را تأیید کنید.', [], 422);
        }

        // 3. IP Rate Limiting: 3 messages per hour per IP
        $ip = get_client_ip();
        $ipRateKey = "contact_form:ip:{$ip}";
        if (!app(\Core\RateLimiter::class)->attempt($ipRateKey, 3, 3600)) {
            return $this->errorResponse('تعداد پیام‌های ارسالی شما بیش از حد مجاز است. لطفاً ساعتی دیگر تلاش کنید.', [], 429);
        }

        // 4. Email Rate Limiting: 5 messages per day
        if (!empty($data['email'])) {
            $email = strtolower(trim((string)$data['email']));
            $normalizedEmail = preg_replace('/\+[^@]*@/', '@', $email);
            $emailKey = "contact_form:email:" . hash('sha256', $normalizedEmail);
            if (!app(\Core\RateLimiter::class)->attempt($emailKey, 5, 86400)) {
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
                'subject' => $subject
            ]);

            return $this->successResponse('پیام شما با موفقیت ارسال شد. به زودی پاسخ خواهیم داد.');

        } catch (\Exception $e) {
            $this->logError('contact.message.storage.failed', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('خطا در ارسال پیام. لطفاً دوباره تلاش کنید.');
        }
    }

    private function verifyCaptcha(?string $token, ?string $response): bool
    {
        if (empty($token) || empty($response)) {
            return false;
        }
        $captchaService = app(\App\Services\CaptchaService::class);
        if (!$captchaService->isEnabled()) {
            return true;
        }
        return $captchaService->verify($token, $response);
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

        if (mb_strlen($data['message']) > 5000) {
            $errors['message'] = 'متن پیام نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد.';
        }

        return $errors;
    }
}
