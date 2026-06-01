<?php

namespace App\Controllers\Admin;

use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Models\Dispute;
use App\Services\InfluencerService;
use App\Services\Shared\DisputeService;
use App\Services\VerificationService;
use App\Services\Search\SearchOrchestrator;
use Core\Logger;
use App\Services\AuditTrail;

class InfluencerController extends BaseAdminController
{
    private InfluencerModel        $profileModel;
    private StoryOrder               $orderModel;
    private Dispute        $disputeModel;
    private InfluencerService    $promotionService;
    private DisputeService           $disputeService;
    private VerificationService      $verificationService;
    private AuditTrail               $auditTrail;
    private SearchOrchestrator $searchService;

    public function __construct(
        InfluencerModel        $profileModel,
        StoryOrder               $orderModel,
        Dispute        $disputeModel,
        InfluencerService    $promotionService,
        DisputeService           $disputeService,
        VerificationService      $verificationService,
        AuditTrail               $auditTrail,
        SearchOrchestrator $searchService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->profileModel       = $profileModel;
        $this->orderModel         = $orderModel;
        $this->disputeModel       = $disputeModel;
        $this->promotionService   = $promotionService;
        $this->disputeService     = $disputeService;
        $this->verificationService = $verificationService;
        $this->auditTrail         = $auditTrail;
        $this->searchService      = $searchService;
    }

    // ──────────────────────────────────────────────────────
    //  لیست سفارش‌ها
    // ──────────────────────────────────────────────────────

    public function orders(): void
    {

        $filters = [
            'status'     => $this->request->get('status'),
            'order_type' => $this->request->get('order_type'),
        ];
        $search = trim($this->request->get('search') ?? '');
        $page   = \max(1, (int) $this->request->get('page', 1));
        $limit  = 30;
        $offset = ($page - 1) * $limit;

        // استفاده از SearchOrchestrator برای جستجو
        if (!empty($search)) {
            $result = $this->searchService->searchInfluencers($search, $filters, $limit, $offset);
            $orders = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
        } else {
            $orders = $this->orderModel->adminList($filters, $limit, $offset);
            $total  = $this->orderModel->adminCount($filters);
        }

        $stats  = $this->orderModel->globalStats();

        view('admin.influencer.orders', [
            'orders'        => $orders,
            'total'         => $total,
            'page'          => $page,
            'pages'         => \ceil($total / $limit),
            'filters'       => $filters,
            'search'        => $search,
            'stats'         => $stats,
            'statusLabels'  => $this->orderModel->statusLabels(),
            'statusClasses' => $this->orderModel->statusClasses(),
        ]);
    }

    // ──────────────────────────────────────────────────────
    //  مدیریت پروفایل‌های اینفلوئنسر
    // ──────────────────────────────────────────────────────

    public function profiles(): void
    {

        $filters = [
            'status' => $this->request->get('status'),
        ];
        $search = trim($this->request->get('search') ?? '');
        $page   = \max(1, (int) $this->request->get('page', 1));
        $limit  = 30;
        $offset = ($page - 1) * $limit;

        // استفاده از SearchOrchestrator برای جستجو
        if (!empty($search)) {
            $result = $this->searchService->searchInfluencers($search, $filters, $limit, $offset);
            $profiles = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
        } else {
            $profiles = $this->profileModel->adminList($filters, $limit, $offset);
            $total    = $this->profileModel->adminCount($filters);
        }

        view('admin.influencer.profiles', [
            'profiles'     => $profiles,
            'total'        => $total,
            'page'         => $page,
            'pages'        => \ceil($total / $limit),
            'filters'      => $filters,
            'search'       => $search,
            'statusLabels' => $this->profileModel->statusLabels(),
        ]);
    }

    /**
     * تایید / رد / تعلیق پروفایل (Ajax)
     * ✅ CSRF verification + Reason validation
     */
    public function approveProfile(): void
    {
        $body      = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $profileId = (int)($body['profile_id'] ?? 0);
        $decision  = $body['decision'] ?? '';
        $reason    = \trim($body['reason'] ?? '');

        if ($profileId <= 0 || !in_array($decision, ['approve', 'reject', 'suspend'], true)) {
            $this->response->json(['success' => false, 'message' => 'پارامترهای نامعتبر.'], 422);
            return;
        }

        $profile = $this->profileModel->find($profileId);
        if (!$profile) {
            $this->response->json(['success' => false, 'message' => 'پروفایل یافت نشد.'], 404);
            return;
        }

        // ✅ Reason validation for reject/suspend
        if (in_array($decision, ['reject', 'suspend'], true)) {
            if (empty($reason) || mb_strlen($reason) < 5) {
                $this->response->json([
                    'success' => false,
                    'message' => 'دلیل باید حداقل ۵ کاراکتر باشد.'
                ], 422);
                return;
            }
        }

        $adminId = $this->userId();
        $verification = null;
        if ($profile->status === 'pending_admin_review') {
            $verification = $this->verificationService->getPendingVerificationByProfile($profileId);
        }

        switch ($decision) {
            case 'approve':
                if ($verification) {
                    $result = $this->verificationService->approveVerification($verification->id, $adminId);
                    $this->response->json($result, $result['ok'] ? 200 : 422);
                    return;
                }

                $this->profileModel->update($profileId, [
                    'status'      => 'verified',
                    'verified_by' => $adminId,
                    'verified_at' => \date('Y-m-d H:i:s'),
                ]);
                $this->auditTrail->record(
                    'influencer.profile.approved',
                    (int)$profile->user_id,
                    [
                        'channel' => 'influencer',
                        'profile_id' => $profileId,
                        'username' => $profile->username,
                    ],
                    $adminId
                );
                $this->response->json(['success' => true, 'message' => 'پیج تایید شد.']);
                break;

            case 'reject':
                if ($verification) {
                    $result = $this->verificationService->rejectVerification(
                        $verification->id,
                        $adminId,
                        e($reason, ENT_QUOTES, 'UTF-8')
                    );
                    $this->response->json($result, $result['ok'] ? 200 : 422);
                    return;
                }

                $this->profileModel->update($profileId, [
                    'status'           => 'rejected',
                    'rejection_reason' => e($reason, ENT_QUOTES, 'UTF-8'),
                ]);
                $this->auditTrail->record(
                    'influencer.profile.rejected',
                    (int)$profile->user_id,
                    [
                        'channel' => 'influencer',
                        'profile_id' => $profileId,
                        'username' => $profile->username,
                        'reason' => $reason,
                    ],
                    $adminId
                );
                $this->response->json(['success' => true, 'message' => 'پیج رد شد.']);
                break;

            case 'suspend':
                $this->profileModel->update($profileId, [
                    'status'           => 'suspended',
                    'is_active'        => 0,
                    'suspended_at'     => \date('Y-m-d H:i:s'),
                    'suspended_reason' => e($reason, ENT_QUOTES, 'UTF-8'),
                ]);
                $this->auditTrail->record(
                    'influencer.profile.suspended',
                    (int)$profile->user_id,
                    [
                        'channel' => 'influencer',
                        'profile_id' => $profileId,
                        'username' => $profile->username,
                        'reason' => $reason,
                    ],
                    $adminId
                );
                $this->response->json(['success' => true, 'message' => 'پیج تعلیق شد.']);
                break;

            default:
                $this->response->json(['success' => false, 'message' => 'تصمیم نامعتبر.'], 422);
        }
    }

    // ──────────────────────────────────────────────────────
    //  لیست اختلاف‌ها
    // ──────────────────────────────────────────────────────

    public function disputes(): void
    {

        $filters = [
            'status' => $this->request->get('status'),
            'search' => $this->request->get('search'),
        ];
        $page   = \max(1, (int) $this->request->get('page', 1));
        $limit  = 30;
        $offset = ($page - 1) * $limit;

        $disputes = $this->disputeModel->adminList($filters, $limit, $offset);
        $total    = $this->disputeModel->adminCount($filters);

        view('admin.influencer.disputes', [
            'disputes'     => $disputes,
            'total'        => $total,
            'page'         => $page,
            'pages'        => \ceil($total / $limit),
            'filters'      => $filters,
            'statusLabels' => $this->disputeModel->statusLabels(),
        ]);
    }

    // ──────────────────────────────────────────────────────
    //  جزئیات یک اختلاف برای داوری
    // ──────────────────────────────────────────────────────

    public function disputeDetail(): void
    {

        $disputeId = (int) $this->request->param('id');
        $dispute   = $this->disputeModel->find($disputeId);

        if (!$dispute) {
            $this->session->setFlash('error', 'اختلاف یافت نشد.');
            redirect(url('/admin/influencer/disputes'));
            return;
        }

        $messages = $this->disputeModel->getMessages($disputeId);
        $order    = $this->orderModel->find((int)$dispute->order_id);

        view('admin.influencer.dispute-detail', [
            'dispute'  => $dispute,
            'messages' => $messages,
            'order'    => $order,
        ]);
    }

    /**
     * صدور رأی مدیر برای اختلاف (Ajax)
     * ✅ CSRF verification + Verdict validation
     */
    public function resolveDispute(): void
    {
        $body          = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $disputeId     = (int)($body['dispute_id'] ?? 0);
        $verdict       = $body['verdict'] ?? '';
        $note          = \trim($body['note'] ?? '');
        $refundPercent = (float)($body['refund_percent'] ?? 0);

        // ✅ Verdict validation
        if (!in_array($verdict, ['favor_influencer', 'favor_customer', 'partial'], true)) {
            $this->response->json(['success' => false, 'message' => 'رأی نامعتبر.'], 422);
            return;
        }

        // ✅ Note validation
        if (empty($note) || mb_strlen($note) < 10) {
            $this->response->json([
                'success' => false,
                'message' => 'توضیحات رأی باید حداقل ۱۰ کاراکتر باشد.'
            ], 422);
            return;
        }

        // ✅ Refund percent validation
        if ($refundPercent < 0 || $refundPercent > 100) {
            $this->response->json([
                'success' => false,
                'message' => 'درصد بازگرداندی باید بین ۰ تا ۱۰۰ باشد.'
            ], 422);
            return;
        }

        $result = $this->disputeService->adminResolve(
            $disputeId,
            $this->userId(),
            $verdict,
            e($note, ENT_QUOTES, 'UTF-8'),
            $refundPercent
        );

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * GET /admin/influencer/verifications
     * List submitted influencer verification requests for admin review
     */
    public function verificationRequests(): void
    {

        $page   = \max(1, (int) $this->request->get('page', 1));
        $limit  = 30;
        $offset = ($page - 1) * $limit;

        $requests = $this->verificationService->getVerificationRequests($limit, $offset);
        $total    = $this->verificationService->countVerificationRequests();

        view('admin.influencer.verifications', [
            'requests' => $requests,
            'page' => $page,
            'pages' => (int) \ceil($total / $limit),
            'total' => $total,
        ]);
    }

    /**
     * POST /admin/influencer/verifications/approve
     */
    public function approveVerification(): void
    {
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $verificationId = (int)($body['verification_id'] ?? 0);

        if ($verificationId <= 0) {
            $this->response->json(['success' => false, 'message' => 'شناسه تایید نامعتبر است.'], 422);
            return;
        }

        $result = $this->verificationService->approveVerification(
            $verificationId,
            $this->userId()
        );

        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * POST /admin/influencer/verifications/reject
     */
    public function rejectVerification(): void
    {
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $verificationId = (int)($body['verification_id'] ?? 0);
        $reason = \trim($body['reason'] ?? '');

        if ($verificationId <= 0 || empty($reason) || mb_strlen($reason) < 5) {
            $this->response->json(['success' => false, 'message' => 'شناسه یا دلیل نامعتبر است.'], 422);
            return;
        }

        $result = $this->verificationService->rejectVerification(
            $verificationId,
            $this->userId(),
            e($reason, ENT_QUOTES, 'UTF-8')
        );

        $this->response->json($result, $result['ok'] ? 200 : 422);
    }
}
