<?php

namespace App\Controllers\Admin;
use App\Services\User\UserService;

use App\Services\KYCService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class KYCController extends BaseAdminController
{
    private UserService $userService;
    private KYCService $kycService;

    public function __construct(
        UserService $userService,
        KYCService $kycService)
    {
        parent::__construct();
        $this->userService = $userService;
        $this->kycService = $kycService;
    }

    public function index(): void
    {
        $status = $this->request->get('status', '');
        $search = $this->request->get('search', '');
        $page = (int)$this->request->get('page', 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = [];
        if ($status !== '') $filters['status'] = $status;
        if ($search !== '') $filters['search'] = $search;

        $kycs = $this->kycService->getAll($filters, $perPage, $offset);
        $total = $this->kycService->count($filters);
        $totalPages = (int)\ceil($total / $perPage);

        // ✅ استفاده از یک کوئری جامع با GROUP BY به جای 4 کوئری جداگانه
        $stats = $this->kycService->getStatsByStatus();

        view('admin.kyc.index', [
            'kycs' => $kycs,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'statusFilter' => $status,
            'search' => $search,
            'stats' => $stats,
        ]);
    }

    public function review(int $id): void
    {
        
        $kyc = $this->kycService->find($id);
        if (!$kyc) {
            $this->session->setFlash('error', 'درخواست KYC یافت نشد');
            redirect('/admin/kyc');
            return;
        }

        $user = $this->userService->find($kyc->user_id);

        // Photoshop check (اگر فایل موجود باشد)
        $photoshopCheck = ['suspicious' => false, 'reasons' => []];
        if (!empty($kyc->verification_image) && $kyc->verification_image !== '[DELETED]') {
            // Whitelist approach
            $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', basename($kyc->verification_image));
            $uploadPath = base_path('storage/uploads/kyc/' . $filename);

            // Verify it's inside the intended directory
            $realPath = realpath($uploadPath);
            $allowedDir = realpath(base_path('storage/uploads/kyc/'));

            if ($realPath !== false && $allowedDir !== false && strpos($realPath, $allowedDir) === 0) {
                if (file_exists($realPath) && is_file($realPath)) {
                    $photoshopCheck = $this->kycService->detectPhotoshop($realPath);
                }
            }
        }

        view('admin.kyc.review', [
            'kyc' => $kyc,
            'user' => $user,
            'photoshopCheck' => $photoshopCheck,
        ]);
    }

    // ✅ Verify: Form-based → Redirect + Flash
public function verify(int $id): void
{
    // لاگ اجرای متد
    $this->logger->info('admin.kyc.verify.hit', [
        'channel' => 'admin_kyc',
        'kyc_id' => $id,
        'admin_id' => user_id(),
    ]);

    $result = $this->kycService->verifyKYC($id, user_id());

    $this->response->json([
        'success' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'نتیجه بررسی',
        'redirect' => url('/admin/kyc')
    ], ($result['success'] ?? false) ? 200 : 400);
}

    // ✅ Reject: Ajax JSON
  public function reject(int $id): void
{
        
    $data = $this->request->json();
    if (!$data) {
        $this->response->json(['success' => false, 'message' => 'داده نامعتبر'], 400);
        return;
    }

    $validator = new \Core\Validator($data, [
        'reason' => 'required|min:10'
    ]);

    if ($validator->fails()) {
        $this->response->json(['success' => false, 'errors' => $validator->errors()], 422);
        return;
    }

    $result = $this->kycService->rejectKYC($id, user_id(), $data['reason']);

    $this->response->json([
        'success' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'خطا',
        'redirect' => url('/admin/kyc')
    ], ($result['success'] ?? false) ? 200 : 400);
}
}