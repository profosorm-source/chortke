<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Services\VitrineService;
use App\Services\VitrineSettingsService;
use App\Contracts\WalletServiceInterface;
use App\Services\AuditTrail;

/**
 * Admin\VitrineController — پنل مدیریت سرویس ویترین
 */
class VitrineController extends BaseAdminController
{
    private VitrineService $service;
    private VitrineSettingsService $settingsService;
    private WalletServiceInterface $wallet;
    private AuditTrail $auditTrail;

    public function __construct(
        VitrineService $service,
        VitrineSettingsService $settingsService,
        WalletServiceInterface $wallet,
        AuditTrail $auditTrail
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->service          = $service;
        $this->settingsService  = $settingsService;
        $this->wallet           = $wallet;
        $this->auditTrail       = $auditTrail;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // لیست آگهی‌ها
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $filters = [
            'status'   => $this->request->get('status')   ?? '',
            'category' => $this->request->get('category') ?? '',
            'type'     => $this->request->get('type')     ?? '',
            'search'   => $this->request->get('search')   ?? '',
        ];

        $page     = max(1, (int) ($this->request->get('page') ?? 1));
        $perPage  = 30;
        
        $data = $this->service->getAdminIndexData($filters, $perPage, ($page - 1) * $perPage);

        view('admin.vitrine.index', array_merge($data, [
            'title'      => 'مدیریت ویترین',
            'page'       => $page,
            'pages'      => (int) ceil($data['total'] / $perPage),
            'filters'    => $filters,
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تایید / رد آگهی
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * تایید آگهی
     * ✅ CSRF verification + Self-approval prevention
     */
    public function approve(int $id): void
    {
        if (!is_admin()) {
            $this->response->json(['success' => false, 'message' => 'Unauthorized'], 403);
            return;
        }

        // ✅ CSRF verification
        $token = $this->request->post('csrf_token') ?? $this->request->header('X-CSRF-TOKEN');
        if (!$token || !$this->csrf->verify($token)) {
            $this->response->json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است (CSRF)'], 403);
            return;
        }

        // ✅ Prevent self-approval
        $listing = $this->service->getSafe($id);
        if (!$listing) {
            $this->response->json(['success' => false, 'message' => 'آگهی یافت نشد.'], 404);
            return;
        }

        if ((int)$listing->seller_id === (int)user_id()) {
            $this->response->json([
                'success' => false,
                'message' => 'نمی‌توانید آگهی خود را تایید کنید.'
            ], 403);
            return;
        }

        $adminId = (int)user_id();
        $result = $this->service->adminApproveListing($id, $adminId);

        if (!empty($result['success'])) {
            $this->auditTrail->record('vitrine.admin_approved', $adminId, [
                'listing_id' => $id,
            ], $adminId);
        }

        $this->response->json([
            'success' => (bool)($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا'
        ], !empty($result['success']) ? 200 : 422);
    }

    /**
     * رد آگهی
     * ✅ CSRF verification + Reason validation
     */
    public function reject(int $id): void
    {
        if (!is_admin()) {
            $this->response->json(['success' => false, 'message' => 'Unauthorized'], 403);
            return;
        }

        // ✅ CSRF verification
        $token = $this->request->post('csrf_token') ?? $this->request->header('X-CSRF-TOKEN');
        if (!$token || !$this->csrf->verify($token)) {
            $this->response->json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است (CSRF)'], 403);
            return;
        }

        $reason = trim((string)$this->request->input('reason'));
        
        // ✅ Reason validation
        if (empty($reason) || mb_strlen($reason) < 5) {
            $this->response->json([
                'success' => false,
                'message' => 'دلیل رد باید حداقل ۵ کاراکتر باشد'
            ], 422);
            return;
        }

        $adminId = (int)user_id();
        $result = $this->service->adminRejectListing($id, $reason, $adminId);

        if (!empty($result['success'])) {
            $this->auditTrail->record('vitrine.admin_rejected', $adminId, [
                'listing_id' => $id,
                'reason' => $reason,
            ], $adminId);
        }

        $this->response->json([
            'success' => (bool)($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا'
        ], !empty($result['success']) ? 200 : 422);
    }
    // ─────────────────────────────────────────────────────────────────────────
    // رسیدگی اختلاف
    // ─────────────────────────────────────────────────────────────────────────

    public function showDispute(): void
    {
        $id      = (int) $this->request->param('id');
        $listing = $this->service->getSafe($id);

        if (!$listing) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            redirect(url('/admin/vitrine'));
            exit;
        }

        view('admin.vitrine.dispute', [
            'title'      => 'رسیدگی به اختلاف — ویترین',
            'listing'    => $listing,
            'categories' => $this->listing->categories(),
            'statuses'   => $this->listing->statuses(),
        ]);
    }

    /**
     * رسیدگی اختلاف - صدور رأی
     * ✅ CSRF verification + Winner validation
     */
    public function resolve(): void
    {
        if (!is_admin()) {
            $this->response->json(['success' => false, 'message' => 'Unauthorized'], 403);
            return;
        }

        // ✅ CSRF verification
        $token = $this->request->post('csrf_token') ?? $this->request->header('X-CSRF-TOKEN');
        if (!$token || !$this->csrf->verify($token)) {
            $this->response->json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است (CSRF)'], 403);
            return;
        }

        $id     = (int) $this->request->param('id');
        $winner = $this->request->post('winner') ?? 'buyer';
        $adminId= (int)user_id();

        // ✅ Winner validation
        if (!in_array($winner, ['buyer', 'seller'], true)) {
            $this->response->json(['success' => false, 'message' => 'مقدار نامعتبر.'], 422);
            return;
        }

        $result = $this->service->resolveDispute($id, $winner, $adminId);
        $this->response->json($result);
    }

    /**
     * آزادسازی دستی اسکرو
     * ✅ CSRF verification + State validation
     */
    public function releaseFunds(): void
    {
        if (!is_admin()) {
            $this->response->json(['success' => false, 'message' => 'Unauthorized'], 403);
            return;
        }

        // ✅ CSRF verification
        $token = $this->request->post('csrf_token') ?? $this->request->header('X-CSRF-TOKEN');
        if (!$token || !$this->csrf->verify($token)) {
            $this->response->json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است (CSRF)'], 403);
            return;
        }

        $id      = (int) $this->request->param('id');
        $listing = $this->service->getSafe($id);

        if (!$listing || $listing->status !== VitrineListing::STATUS_IN_ESCROW) {
            $this->response->json([
                'success' => false,
                'message' => 'آگهی یافت نشد یا در escrow نیست.'
            ], 404);
            return;
        }

        $adminId = (int)user_id();
        $result  = $this->service->releaseFundsToSeller($listing, 'admin_manual');

        if ($result['success']) {
            $this->auditTrail->record('vitrine.admin_release', $adminId, [
                'listing_id' => $id,
                'net'        => $result['net'] ?? 0,
            ]);
        }

        $this->response->json($result);
    }

    /**
     * بازگرداندی آگهی
     * ✅ CSRF verification
     */
    public function refund(int $id): void
    {
        if (!is_admin()) {
            $this->response->json(['success' => false, 'message' => 'Unauthorized'], 403);
            return;
        }

        // ✅ CSRF verification
        $token = $this->request->post('csrf_token') ?? $this->request->header('X-CSRF-TOKEN');
        if (!$token || !$this->csrf->verify($token)) {
            $this->response->json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است (CSRF)'], 403);
            return;
        }

        $adminId = (int)user_id();
        $result = $this->service->adminRefundListing($id, $adminId);

        if (!empty($result['success'])) {
            $this->auditTrail->record('vitrine.admin_refunded', $adminId, [
                'listing_id' => $id,
            ], $adminId);
        }

        $this->response->json([
            'success' => (bool)($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا'
        ], !empty($result['success']) ? 200 : 422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تنظیمات ویترین
    // ─────────────────────────────────────────────────────────────────────────

    public function settings(): void
    {
        $settings = $this->settingsService->getSettings();
        view('admin.vitrine.settings', array_merge([
            'title' => 'تنظیمات ویترین',
        ], $settings));
    }

    public function saveSettings(): void
    {
        $data = [
            'vitrine_commission_percent'    => $this->request->post('vitrine_commission_percent'),
            'vitrine_escrow_days'           => $this->request->post('vitrine_escrow_days'),
            'vitrine_kyc_required'          => $this->request->post('vitrine_kyc_required'),
            'vitrine_min_price_usdt'        => $this->request->post('vitrine_min_price_usdt'),
            'vitrine_max_price_usdt'        => $this->request->post('vitrine_max_price_usdt'),
            'vitrine_max_active_per_user'   => $this->request->post('vitrine_max_active_per_user'),
            'vitrine_enabled'               => $this->request->post('vitrine_enabled'),
        ];

        $result = $this->settingsService->saveSettings(array_filter($data, fn($v) => $v !== null));
        $this->jsonOrRedirect($result['success'], $result['message'], url('/admin/vitrine/settings'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    private function jsonOrRedirect(bool $ok, string $msg, string $redirect): void
    {
        if (is_ajax()) {
            $this->response->json(['success' => $ok, 'message' => $msg]);
            return;
        }
        $this->session->setFlash($ok ? 'success' : 'error', $msg);
        redirect($redirect);
    }
}
