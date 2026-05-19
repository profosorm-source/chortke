<?php
namespace App\Controllers;

use App\Controllers\BaseController;

/**
 * Contact Form Controller
 */
class ContactController extends BaseController
{
    private \App\Services\ContactService $contactService;

    public function __construct(\App\Services\ContactService $contactService)
    {
        parent::__construct();
        $this->contactService = $contactService;
    }

    /**
     * ارسال پیام تماس
     */
    public function send()
    {
        $this->validateCsrf();

        // 🛡️ HIGH-08: ریت لیمیت فرم تماس در سطح کنترلر جهت مقابله با دور زدن لایه سرویس
        try {
            rate_limit('social', 'message', "contact_ip_" . get_client_ip());
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                return $this->response->json([
                    'success' => false,
                    'message' => 'تعداد پیام‌های ارسالی شما بیش از حد مجاز است. لطفاً ساعتی دیگر تلاش کنید.'
                ], 429);
            }
        }

        $name = (string)$this->request->input('name');
        $subject = (string)$this->request->input('subject');

        // 🛡️ MED-09: اعتبارسنجی طول فیلدهای نام و موضوع جهت مقابله با سرریز حافظه
        if (mb_strlen($name) > 100) {
            return $this->response->json([
                'success' => false,
                'message' => 'نام نامعتبر است (حداکثر ۱۰۰ کاراکتر مجاز است).'
            ], 422);
        }
        if (mb_strlen($subject) > 200) {
            return $this->response->json([
                'success' => false,
                'message' => 'موضوع نامعتبر است (حداکثر ۲۰۰ کاراکتر مجاز است).'
            ], 422);
        }

        $data = [
            'name' => $name,
            'email' => $this->request->input('email'),
            'subject' => $subject,
            'message' => $this->request->input('message'),
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