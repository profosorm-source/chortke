<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Dispute;
use Core\Database;

/**
 * DisputeController - کنترلر مرکزی برای نمایش و پیگیری اختلافات کاربران.
 */
class DisputeController extends BaseController
{
    public function __construct(
        private Dispute $disputeModel,
        private Database $db
    ) {
        parent::__construct();
    }

    /**
     * لیست کل اختلافات کاربر (شامل باز و بسته شده)
     */
    public function index(): string
    {
        $userId = user_id();
        
        // Fetching all disputes where user is either initiator or target.
        $disputes = $this->db->fetchAll("
            SELECT d.*, 
                   COALESCE(cu.full_name, 'کاربر') as creator_name,
                   COALESCE(tu.full_name, 'طرف مقابل') as target_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users tu ON tu.id = d.target_user_id
            WHERE d.user_id = ? OR d.target_user_id = ?
            ORDER BY d.updated_at DESC
        ", [$userId, $userId]);

        return view('user.disputes.index', [
            'disputes' => $disputes,
            'model'    => $this->disputeModel // To access labels/constants
        ]);
    }

    /**
     * نمایش جزئیات و چت یک پرونده اختلاف
     */
    public function show(): string
    {
        $id = (int)$this->request->param('id');
        $userId = user_id();

        $dispute = $this->disputeModel->find($id);
        
        // Security: Ensure user is party to this dispute
        if (!$dispute || ((int)$dispute->user_id !== $userId && (int)($dispute->target_user_id ?? 0) !== $userId)) {
            $this->session->setFlash('error', 'شما دسترسی به این پرونده ندارید.');
            $this->response->redirect(url('/disputes'));
            exit;
        }

        $messages = $this->disputeModel->getMessages($id);

        return view('user.disputes.show', [
            'dispute'  => $dispute,
            'messages' => $messages,
            'model'    => $this->disputeModel
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

        $dispute = $this->disputeModel->find($id);
        if (!$dispute || ((int)$dispute->user_id !== $userId && (int)($dispute->target_user_id ?? 0) !== $userId)) {
             $this->response->redirect(url('/disputes'));
             exit;
        }

        // Auto determine role
        $role = ((int)$dispute->user_id === $userId) ? 'creator' : 'opponent';

        $ok = $this->disputeModel->addMessage($id, $userId, $text, null, $role);

        if ($ok) {
            // Mark updated
            $this->db->query("UPDATE disputes SET updated_at = NOW() WHERE id = ?", [$id]);
            $this->session->setFlash('success', 'پیام شما ثبت شد.');
        } else {
            $this->session->setFlash('error', 'خطا در ثبت پیام.');
        }

        $this->response->redirect(url("/disputes/{$id}"));
    }
}
