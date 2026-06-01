<?php
namespace App\Controllers;

use App\Controllers\BaseController;

/**
 * Contact Form Controller
 */
class ContactController extends BaseController
{
    private \App\Services\ContactService $contactService;

    public function __construct(\App\Services\ContactService $contactService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->contactService = $contactService;
    }

    /**
     * ارسال پیام تماس
     */
    public function send()
    {
        $this->validateCsrf();

        // 🛡️ MED-03: استفاده از نام‌های فیلد گمراه‌کننده جهت جلوگیری از دور زدن Honeypot توسط بات‌های هوشمند
        $honeypots = ['user_name', 'confirm_email', 'address', 'phone_number_ext'];
        foreach ($honeypots as $field) {
            if (!empty($this->request->input($field))) {
                // لاگ آی‌پی بات
                $ip = $this->request->ip();
                $this->logger->warning('honeypot_triggered', ['ip' => $ip, 'field' => $field]);
                
                // بازگشت پاسخ موفق دروغین به بات‌ها
                return $this->response->json([
                    'success' => true,
                    'message' => 'پیام شما با موفقیت ارسال شد. به زودی پاسخ خواهیم داد.'
                ]);
            }
        }

        $name = (string)$this->request->input('name');
        $subject = (string)$this->request->input('subject');
        $email = trim((string)$this->request->input('email'));
        $message = (string)$this->request->input('message');

        // 🛡️ MED-09: اعتبارسنجی طول فیلدهای نام و موضوع جهت مقابله با سرریز حافظه
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            return $this->response->json([
                'success' => false,
                'message' => 'نام باید بین ۲ تا ۱۰۰ کاراکتر باشد.'
            ], 422);
        }
        if (mb_strlen($subject) < 5 || mb_strlen($subject) > 200) {
            return $this->response->json([
                'success' => false,
                'message' => 'موضوع باید بین ۵ تا ۲۰۰ کاراکتر باشد.'
            ], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->json([
                'success' => false,
                'message' => 'ایمیل معتبر الزامی است.'
            ], 422);
        }
        if (mb_strlen($message) < 10 || mb_strlen($message) > 5000) {
            return $this->response->json([
                'success' => false,
                'message' => 'متن پیام باید بین ۱۰ تا ۵۰۰۰ کاراکتر باشد.'
            ], 422);
        }

        $data = [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'website' => $this->request->input('website'),
            'captcha_token' => $this->request->input('captcha_token'),
            'captcha_response' => $this->request->input('captcha_response'),
        ];

        $result = $this->contactService->sendMessage($data);

        if (!$result['success']) {
            return $this->response->json(['success' => false, 'message' => $result['message'], 'errors' => $result['errors'] ?? []], $result['status_code'] ?? 422);
        }

        return $this->response->json(['success' => true, 'message' => $result['message']]);
    }
}