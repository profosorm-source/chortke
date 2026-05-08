<?php

namespace App\Controllers\Admin;
use App\Services\User\UserService;

use App\Models\KYCVerification;
use App\Services\KYCService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class KYCController extends BaseAdminController
{
    private UserService $userService;
    private KYCVerification $kycModel;
    private KYCService $kycService;

    public function __construct(
        UserService $userService,
        KYCVerification $kycModel,
        KYCService $kycService)
    {
        parent::__construct();
        $this->userService = $userService;
        $this->kycModel  = $kycModel;
        $this->kycService = $kycService;
    }

    public function index(): void
    {
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';
        $page = (int)($_GET['page'] ?? 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = [];
        if ($status !== '') $filters['status'] = $status;
        if ($search !== '') $filters['search'] = $search;

        $kycs = $this->kycModel->getAll($filters, $perPage, $offset);
        $total = $this->kycModel->count($filters);
        $totalPages = (int)\ceil($total / $perPage);

        view('admin.kyc.index', [
            'kycs' => $kycs,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'statusFilter' => $status,
            'search' => $search,
            'stats' => [
                'pending' => $this->kycModel->count(['status' => 'pending']),
                'under_review' => $this->kycModel->count(['status' => 'under_review']),
                'verified' => $this->kycModel->count(['status' => 'verified']),
                'rejected' => $this->kycModel->count(['status' => 'rejected']),
            ],
        ]);
    }

    public function review(int $id): void
    {
        
        $kyc = $this->kycModel->find($id);
        if (!$kyc) {
            $this->session->setFlash('error', 'درخواست KYC یافت نشد');
            redirect('/admin/kyc');
            return;
        }

        $user = $this->userService->find($kyc->user_id);

        // Photoshop check (اگر فایل موجود باشد)
        $photoshopCheck = ['suspicious' => false, 'reasons' => []];
        if (!empty($kyc->verification_image) && $kyc->verification_image !== '[DELETED]') {
            $uploadPath = __DIR__ . '/../../../storage/uploads/kyc/' . $kyc->verification_image;
            if (\file_exists($uploadPath)) {
                $photoshopCheck = $this->kycService->detectPhotoshop($uploadPath);
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

    if (($result['success'] ?? false) === true) {
        $this->session->setFlash('success', $result['message'] ?? 'احراز هویت تأیید شد');
    } else {
        $this->session->setFlash('error', $result['message'] ?? 'خطا در تأیید احراز هویت');
    }

    $this->response->redirect(url('/admin/kyc/review/' . $id));
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