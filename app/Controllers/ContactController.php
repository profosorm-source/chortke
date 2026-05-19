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
        $data = [
            'name' => $this->request->input('name'),
            'email' => $this->request->input('email'),
            'subject' => $this->request->input('subject'),
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