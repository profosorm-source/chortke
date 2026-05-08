<?php
namespace App\Controllers;
use Core\Request;
use Core\Response;

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
        $result = $this->contactService->sendMessage($this->request->all());

        if (!$result['success']) {
            return $this->response->error($result['message'], $result['errors'] ?? [], $result['status_code'] ?? 400);
        }

        return $this->response->success($result['message']);
    }
}