<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\Shared\DisputeService;

/**
 * DisputeController - کنترلر مرکزی برای نمایش و پیگیری اختلافات کاربران.
 */
class DisputeController extends BaseController
{
    private DisputeService $disputeService;
    public function __construct(
        DisputeService $disputeService
    , ?\App\Contracts\LoggerInterface $logger = null) {        $this->disputeService = $disputeService;

        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * لیست کل اختلافات کاربر (شامل باز و بسته شده)
     */
    public function index(): string
    {
        $userId = user_id();
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // Get disputes from service
        $disputes = $this->disputeService->getUserDisputes($userId, $limit, $offset);
        $total = $this->disputeService->countUserDisputes($userId);

        return view('user.disputes.index', [
            'disputes' => $disputes,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * نمایش جزئیات و چت یک پرونده اختلاف
     */
    public function show(): string
    {
        $id = (int)$this->request->param('id');
        $userId = user_id();

        // Get dispute from service
        $dispute = $this->disputeService->find($id);
        
        // Security: Ensure user is party to this dispute
        if (!$dispute || ((int)$dispute->user_id !== $userId && (int)($dispute->target_user_id ?? 0) !== $userId)) {
            $this->session->setFlash('error', 'شما دسترسی به این پرونده ندارید.');
            $this->response->redirect(url('/disputes'));
            exit;
        }

        // Get messages from service
        $messages = $this->disputeService->getMessages($id);

        return view('user.disputes.show', [
            'dispute'  => $dispute,
            'messages' => $messages,
        ]);
    }

    /**
     * ارسال پیام جدید در چت پرونده
     */
    public function addMessage(): void
    {
        $id = (int)$this->request->param('id');
        $userId = user_id();
        $body = $this->request->body();
        $text = trim($body['message'] ?? '');

        if (!$text) {
            $this->session->setFlash('error', 'متن پیام الزامی است.');
            $this->response->redirect(url("/disputes/{$id}"));
            return;
        }

        $result = $this->disputeService->addMessageWithContext($id, $userId, $text);
        
        if ($result['success']) {
            $this->session->setFlash('success', 'پیام شما ثبت شد.');
        } else {
            $this->session->setFlash('error', $result['message'] ?? 'خطا در ثبت پیام.');
        }

        $this->response->redirect(url("/disputes/{$id}"));
    }
}
