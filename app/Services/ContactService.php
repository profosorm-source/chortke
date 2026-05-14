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
                'ip_address' => get_client_ip(),
                'created_at' => now(),
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

        if (empty($data['name'])) {
            $errors['name'] = 'نام الزامی است.';
        }

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'ایمیل معتبر الزامی است.';
        }

        if (empty($data['subject'])) {
            $errors['subject'] = 'موضوع الزامی است.';
        }

        if (empty($data['message'])) {
            $errors['message'] = 'پیام الزامی است.';
        }

        return $errors;
    }
}

