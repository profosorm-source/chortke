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
        // H-07: Rate Limiting by IP to prevent spam flood
        $ip = get_client_ip();
        $rateKey = "contact_form:{$ip}";
        // محدودیت ۵ پیام در ساعت برای هر IP
        if (!app(\Core\RateLimiter::class)->attempt($rateKey, 5, 3600)) {
            return $this->errorResponse('شما بیش از حد مجاز پیام ارسال کرده‌اید. لطفاً ساعتی دیگر تلاش کنید.', [], 429);
        }

        // Validation logic
        $errors = $this->validate($data);
        if (!empty($errors)) {
            return $this->errorResponse('لطفاً تمام فیلدها را به درستی پر کنید.', $errors, 422);
        }

        try {
            $this->contactMessageModel->createMessage([
                'name' => $data['name'],
                'email' => $data['email'],
                'subject' => $data['subject'],
                'message' => $data['message'],
                'ip_address' => $ip,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->logInfo('contact.message.stored', [
                'email' => $data['email'],
                'subject' => $data['subject']
            ]);

            return $this->successResponse('پیام شما با موفقیت ارسال شد. به زودی پاسخ خواهیم داد.');

        } catch (\Exception $e) {
            $this->logError('contact.message.storage.failed', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('خطا در ارسال پیام. لطفاً دوباره تلاش کنید.');
        }
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
