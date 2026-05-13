<?php
// app/Controllers/Admin/SocialAccountController.php

namespace App\Controllers\Admin;

use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use App\Controllers\Admin\BaseAdminController;

class SocialAccountController extends BaseAdminController
{
    private \App\Services\SocialAccountService $socialAccountService;
    private SocialAccountService $service;

    private SocialAccount $socialAccountModel;

    public function __construct(
        \App\Models\SocialAccount $socialAccountModel,
        \App\Services\SocialAccountService $socialAccountService)
    {
        parent::__construct();
        $this->socialAccountService = $socialAccountService;
        $this->socialAccountModel = $socialAccountModel;
        $this->service = $this->socialAccountService;
    }

    /**
     * لیست تمام حساب‌ها
     */
    public function index()
    {
        $page = (int) ($_GET['page'] ?? 1);
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $filters = [];
        if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
        if (!empty($_GET['platform'])) $filters['platform'] = $_GET['platform'];
        if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

        $accounts = $this->socialAccountModel->getAll($filters, $limit, $offset);
        $total = $this->socialAccountModel->countAll($filters);
        $totalPages = \ceil($total / $limit);

        return view('admin.social-accounts.index', [
            'accounts'   => $accounts,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }

    /**
     * جزئیات حساب
     */
    public function show()
    {
                $id = (int) $this->request->param('id');

        $account = $this->socialAccountModel->find($id);
        if (!$account) {
            $this->session->setFlash('error', 'حساب یافت نشد.');
            return redirect(url('/admin/social-accounts'));
        }

        return view('admin.social-accounts.show', [
            'account' => $account,
        ]);
    }

    /**
     * تایید — Ajax
     */
    public function verify()
    {
                        $id = (int) $this->request->param('id');

        $result = $this->service->verify($id, user_id());

        $this->logger->activity('social_account_verify', 'تایید حساب اجتماعی #' . $id, user_id(), ['entity_type' => 'social_account', 'entity_id' => $id]);

        return $this->response->json($result);
    }

    /**
     * رد — Ajax
     */
    public function reject()
    {
                        $id = (int) $this->request->param('id');

        $body = $this->request->body();
        $reason = $body['reason'] ?? '';

        if (empty($reason)) {
            return $this->response->json(['success' => false, 'message' => 'لطفاً دلیل رد را وارد کنید.']);
        }

        $result = $this->service->reject($id, user_id(), $reason);

        $this->logger->activity('social_account_reject', 'رد حساب اجتماعی #' . $id . ': ' . $reason, user_id(), ['entity_type' => 'social_account', 'entity_id' => $id]);

        return $this->response->json($result);
    }
}