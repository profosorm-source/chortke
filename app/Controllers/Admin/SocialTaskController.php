<?php

namespace App\Controllers\Admin;

use App\Services\Search\SearchOrchestrator;
use App\Services\SocialTask\SocialTaskService;
use App\Services\Gamification\TrustService;
use App\Enums\ModuleContext;
use App\Services\User\UserService;
use App\Services\SocialTask\RatingService;
use App\Services\SocialTask\SilentAntiFraudService;
use App\Contracts\WalletServiceInterface;
use App\Services\AuditTrail;
use Core\Database;


class SocialTaskController extends BaseAdminController
{
    private SearchOrchestrator $searchService;
    private SocialTaskService      $service;
    private TrustService           $trust;
    private UserService            $userService;
    private RatingService          $ratingService;
    private SilentAntiFraudService $antiFraud;
    private WalletServiceInterface $wallet;
    private Database               $db;
    private AuditTrail $auditTrail;

    public function __construct(
        SearchOrchestrator     $searchService,
        SocialTaskService      $service,
        TrustService           $trust,
        UserService            $userService,
        RatingService          $ratingService,
        SilentAntiFraudService $antiFraud,
        WalletServiceInterface $wallet,
        Database               $db,
        AuditTrail             $auditTrail
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->searchService = $searchService;
        $this->service       = $service;
        $this->trust         = $trust;
        $this->userService   = $userService;
        $this->ratingService = $ratingService;
        $this->antiFraud     = $antiFraud;
        $this->wallet        = $wallet;
        $this->db            = $db;
        $this->auditTrail    = $auditTrail;
    }

    // ─────────────────────────────────────────────────────────────
    // آگهی‌ها
    // ─────────────────────────────────────────────────────────────

    public function index(): void
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $limit   = 30;
        $offset  = ($page - 1) * $limit;
        $filters = [
            'status'   => $this->request->get('status')   ?? '',
            'platform' => $this->request->get('platform') ?? '',
            'search'   => $this->request->get('search')   ?? '',
        ];

        if (!empty($filters['search'])) {
            $result = $this->searchService->searchAdTasks($filters['search'], array_merge($filters, ['type' => 'social_task']), $limit, $offset);
            $ads = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
        } else {
            [$ads, $total] = $this->service->getAdsForAdmin($filters, $limit, $offset);
        }

        $stats = $this->service->getAdStatsForAdmin();

        view('admin.social-tasks.index', [
            'title'      => 'مدیریت آگهی‌های اجتماعی',
            'ads'        => $ads,
            'stats'      => $stats,
            'filters'    => $filters,
            'page'       => $page,
            'total'      => $total,
            'totalPages' => (int)ceil($total / $limit),
        ]);
    }

    public function show(): void
    {
        $id  = (int)$this->request->param('id');
        $ad  = $this->service->getAdByIdForAdmin($id);

        if (!$ad) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            redirect(url('/admin/social-tasks'));
            return;
        }

        $executions  = $this->service->getAdExecutions($id, 50, 0);
        $adStats     = $this->service->getAdvertiserAdStats((int)$ad->adS_id, $id);

        view('admin.social-tasks.show', [
            'title'      => 'جزئیات آگهی #' . $id,
            'ad'         => $ad,
            'executions' => $executions,
            'adStats'    => $adStats,
        ]);
    }

    public function approve(): void
    {
        $id     = (int)$this->request->param('id');
        $result = $this->changeAdStatus($id, 'active', 'admin_approved');

        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/admin/social-tasks'));
    }

   public function reject(int $id): void
{
    if (!is_admin()) {
        $this->response->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        return;
    }

    $reason = trim((string)($_POST['reason'] ?? ''));
    if (empty($reason) || mb_strlen($reason) < 5) {
        $this->response->json(['ok' => false, 'message' => 'دلیل رد باید حداقل ۵ کاراکتر باشد'], 422);
        return;
    }

    $result = $this->service->adminRejectAd(admin_id(), $id, e($reason, ENT_QUOTES, 'UTF-8'));

    if (!empty($result['success'])) {
        $this->auditTrail->record('social_ad.rejected', admin_id(), [
            'ad_id' => $id,
            'reason' => $reason,
        ]);
    }

    $this->response->json([
        'ok' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'خطا',
    ], !empty($result['success']) ? 200 : 422);
}

    public function pause(): void
    {

        $result = $this->changeAdStatus((int)$this->request->param('id'), 'paused', 'admin_paused');
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/admin/social-tasks'));
    }

    public function resume(): void
    {

        $result = $this->changeAdStatus((int)$this->request->param('id'), 'active', 'admin_resumed');
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/admin/social-tasks'));
    }

    public function cancel(int $id): void
{
    if (!is_admin()) {
        $this->response->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        return;
    }

    $result = $this->service->adminCancelAd(admin_id(), $id);

    if (!empty($result['success'])) {
        $this->auditTrail->record('social_ad.cancelled', admin_id(), [
            'ad_id' => $id,
            'refund' => $result['refund'] ?? 0,
        ]);
    }

    $this->response->json([
        'ok' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'خطا',
    ], !empty($result['success']) ? 200 : 422);
}
    // ─────────────────────────────────────────────────────────────
    // اجراها (Executions)
    // ─────────────────────────────────────────────────────────────

    public function executions(): void
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $limit   = 30;
        $filters = [
            'decision'  => $this->request->get('decision')  ?? '',
            'platform'  => $this->request->get('platform')  ?? '',
            'flag'      => $this->request->get('flag')      ?? '',
            'search'    => $this->request->get('search')    ?? '',
        ];

        [$executions, $total] = $this->service->getExecutionsForAdmin($filters, $limit, ($page - 1) * $limit);
        $execStats            = $this->service->getExecutionStatsForAdmin();

        view('admin.social-tasks.executions', [
            'title'      => 'اجراهای تسک اجتماعی',
            'executions' => $executions,
            'execStats'  => $execStats,
            'filters'    => $filters,
            'page'       => $page,
            'total'      => $total,
            'totalPages' => (int)ceil($total / $limit),
        ]);
    }

    public function executionShow(): void
    {
        $id   = (int)$this->request->param('id');
        $exec = $this->service->getExecutionByIdForAdmin($id);

        if (!$exec) {
            $this->session->setFlash('error', 'اجرا یافت نشد.');
            redirect(url('/admin/social-executions'));
            return;
        }

        // behavior data decoded
        $behaviorData = [];
        if (!empty($exec->behavior_data)) {
            $behaviorData = json_decode($exec->behavior_data, true) ?? [];
        }

        view('admin.social-tasks.execution-show', [
            'title'        => 'جزئیات اجرا #' . $id,
            'exec'         => $exec,
            'behaviorData' => $behaviorData,
            'trustScore'   => ($u = $this->userService->findById((int)$exec->executor_id)) ? $this->trust->getTrustScore($u, ModuleContext::SOCIAL_TASKS) : 50.0,
            'restriction'  => $this->antiFraud->getRestrictionLevel((int)$exec->executor_id),
        ]);
    }

    public function reviewRatings(): void
    {
        $page   = max(1, (int)($this->request->get('page') ?? 1));
        $limit  = 30;
        $offset = ($page - 1) * $limit;

        $ratings = $this->ratingService->getPendingReviews($limit, $offset);
        $stats   = $this->ratingService->getReviewStats();

        view('admin.social-tasks.rating-reviews', [
            'title'   => 'نظرات و امتیازات منتظر بررسی',
            'ratings' => $ratings,
            'stats'   => $stats,
            'page'    => $page,
        ]);
    }

    public function reviewRatingDetail(): void
    {
        $id = (int)$this->request->param('id');
        $review = $this->ratingService->getReviewById($id);

        if (!$review) {
            $this->session->setFlash('error', 'نظری یافت نشد.');
            redirect(url('/admin/social-task-reviews'));
            return;
        }

        view('admin.social-tasks.rating-review-detail', [
            'title'  => 'جزئیات امتیاز و نظر',
            'review' => $review,
        ]);
    }

    public function moderateRating(): void
    {

        $reviewId = (int)$this->request->param('id');
        $action   = $this->request->post('action');
        $status   = $action === 'approve' ? 'approved' : 'rejected';

        $result = $this->ratingService->moderateReview($reviewId, $status, admin_id());

        if (is_ajax()) {
            $this->response->json($result);
            return;
        }

        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/admin/social-task-reviews'));
    }

    public function flagExecution(): void
{
    $id   = (int)$this->request->param('id');
    $note = trim((string)($this->request->post('note') ?? ''));

    $result = $this->service->adminFlagExecution(admin_id(), $id, $note);

    if (!empty($result['success'])) {
        $this->auditTrail->record('social_exec.flagged', admin_id(), [
            'execution_id' => $id,
            'note' => $note,
        ]);
    }

    $this->response->json([
        'success' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'خطا'
    ], !empty($result['success']) ? 200 : 422);
}
    
	
	public function overrideDecision(): void
{
    $id       = (int)$this->request->param('id');
    $decision = (string)($this->request->post('decision') ?? '');
    $reason   = trim((string)($this->request->post('reason') ?? ''));

    if (!in_array($decision, ['approved', 'soft_approved', 'rejected'], true)) {
        $this->response->json(['success' => false, 'message' => 'تصمیم معتبر نیست']);
        return;
    }

    if ($reason === '') {
        $this->response->json(['success' => false, 'message' => 'دلیل override الزامی است']);
        return;
    }

    $result = $this->service->adminOverrideExecution(admin_id(), $id, $decision, $reason);

    if (!empty($result['success'])) {
        $this->auditTrail->record('social_exec.override', admin_id(), [
            'execution_id' => $id,
            'old_decision' => $result['old_decision'] ?? null,
            'new_decision' => $decision,
            'reason' => $reason,
        ]);
    }

    $this->response->json([
        'success' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'خطا',
    ], !empty($result['success']) ? 200 : 422);
}

    // ─────────────────────────────────────────────────────────────
    // Trust Dashboard
    // ─────────────────────────────────────────────────────────────

    public function trustDashboard(): void
    {
        $page  = max(1, (int)($this->request->get('page') ?? 1));
        $limit = 30;

        $lowTrustUsers  = $this->service->getLowTrustUsersForAdmin($limit, ($page - 1) * $limit);
        $totalLow       = $this->service->countLowTrustUsersForAdmin();
        $trustStats     = $this->service->getTrustStatsForAdmin();

        view('admin.social-tasks.trust', [
            'title'         => 'داشبورد Trust Score',
            'lowTrustUsers' => $lowTrustUsers,
            'trustStats'    => $trustStats,
            'page'          => $page,
            'total'         => $totalLow,
            'totalPages'    => (int)ceil($totalLow / $limit),
        ]);
    }

    public function userTrust(): void
    {
        $userId  = (int)$this->request->param('id');
        $userObj = $this->userService->findById($userId);
        $trust   = $userObj ? $this->trust->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;
        $weekly  = []; // To be implemented with Score analytics
        $history = $this->service->getTrustHistoryForAdmin($userId);
        $restriction = $this->antiFraud->getRestrictionLevel($userId);

        $user = $this->db->fetch("SELECT id, full_name, email FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            $this->session->setFlash('error', 'کاربر یافت نشد.');
            redirect(url('/admin/social-trust'));
            return;
        }

        view('admin.social-tasks.user-trust', [
            'title'       => 'Trust Score کاربر: ' . ($user->full_name ?? ''),
            'user'        => $user,
            'trust'       => $trust,
            'weekly'      => $weekly,
            'history'     => $history,
            'restriction' => $restriction,
        ]);
    }




public function adjustTrust(): void
{
    $userId = (int)$this->request->param('id');
    $delta  = (float)($this->request->post('delta') ?? 0);
    $reason = trim((string)($this->request->post('reason') ?? ''));

    if ($reason === '') {
        $this->response->json(['success' => false, 'message' => 'دلیل الزامی است']);
        return;
    }

    if ($delta == 0.0) {
        $this->response->json(['success' => false, 'message' => 'مقدار تغییر نمی‌تواند صفر باشد']);
        return;
    }

    $result = $this->service->adminAdjustTrust(admin_id(), $userId, $delta, $reason);

    if (!empty($result['success'])) {
        $this->auditTrail->record('social_trust.adjusted', admin_id(), [
            'user_id' => $userId,
            'delta' => $delta,
            'old_trust' => $result['old_trust'] ?? null,
            'new_trust' => $result['new_trust'] ?? null,
            'reason' => $reason,
        ]);
    }

    $this->response->json([
        'success' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'خطا',
        'new_trust' => $result['new_trust'] ?? null,
    ], !empty($result['success']) ? 200 : 422);
}


    // ─────────────────────────────────────────────────────────────
    // آمار کلی
    // ─────────────────────────────────────────────────────────────

    public function stats(): void
    {
        $stats = $this->service->getFullStatsForAdmin();

        view('admin.social-tasks.stats', [
            'title' => 'آمار SocialTask',
            'stats' => $stats,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers — Status changes
    // ─────────────────────────────────────────────────────────────

   private function changeAdStatus(int $id, string $status, string $auditEvent): array
{
    $result = $this->service->adminChangeAdStatus(admin_id(), $id, $status);

    if (!empty($result['success'])) {
        $this->auditTrail->record('social_ad.' . $auditEvent, admin_id(), [
            'ad_id' => $id,
            'status' => $status,
        ]);
    }

    return [
        'success' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'خطا',
    ];
}
}
