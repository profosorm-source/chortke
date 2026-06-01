<?php

namespace App\Controllers\Api;

use App\Services\SocialTask\SocialTaskService;
use App\Services\SocialTask\SilentAntiFraudService;
use App\Services\Gamification\TrustService;
use App\Enums\ModuleContext;
use App\Services\User\UserService;

/**
 * SocialTaskApiController - API برای سیستم وظایف اجتماعی
 *
 * Endpoints:
 * - GET /api/v1/social/accounts
 * - POST /api/v1/social/accounts
 * - PUT /api/v1/social/accounts/{id}
 * - DELETE /api/v1/social/accounts/{id}
 * - GET /api/v1/social/ads
 * - POST /api/v1/social/ads
 * - GET /api/v1/social/ads/{id}
 * - POST /api/v1/social/ads/{id}/pause
 * - POST /api/v1/social/ads/{id}/resume
 * - POST /api/v1/social/ads/{id}/cancel
 * - GET /api/v1/social/tasks
 * - POST /api/v1/social/tasks/{id}/start
 * - POST /api/v1/social/tasks/{id}/submit
 * - GET /api/v1/social/tasks/history
 * - POST /api/v1/social/executions/{id}/dispute
 * - GET /api/v1/social/disputes
 * 
 * Legacy endpoints (برای سازگاری عقب‌رو):
 * - POST /api/social-tasks/behavior
 * - POST /api/social-tasks/camera-verify
 * - GET /api/social-tasks/trust-status
 */
class SocialTaskApiController extends BaseApiController
{
    private SocialTaskService      $service;
    private SilentAntiFraudService $antiFraud;
    private TrustService           $trust;
    private UserService            $userService;

    public function __construct(
        SocialTaskService      $service,
        SilentAntiFraudService $antiFraud,
        TrustService           $trust,
        UserService            $userService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->service     = $service;
        $this->antiFraud   = $antiFraud;
        $this->trust       = $trust;
        $this->userService = $userService;
    }

    // ═════════════════════════════════════════════════════════════
    // SOCIAL ACCOUNTS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست حساب‌های اجتماعی کاربر
     * GET /api/v1/social/accounts
     */
    public function accounts(): void
    {
        $user = $this->currentUser();
        $accounts = $this->service->getUserAccounts($user->id);
        $this->success($accounts);
    }

    /**
     * ایجاد حساب اجتماعی جدید
     * POST /api/v1/social/accounts
     */
    public function storeAccount(): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();

        $platform = trim((string)($data['platform'] ?? ''));
        $account_handle = trim((string)($data['account_handle'] ?? ''));
        $access_token = trim((string)($data['access_token'] ?? ''));

        if (empty($platform) || empty($account_handle)) {
            $this->validationError(['platform' => 'الزامی', 'account_handle' => 'الزامی']);
        }

        $result = $this->service->addAccount($user->id, $platform, $account_handle, $access_token);
        $this->success($result);
    }

    /**
     * به‌روزرسانی حساب اجتماعی
     * PUT /api/v1/social/accounts/{id}
     */
    public function updateAccount(string $id): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();
        $result = $this->service->updateUserAccount($user->id, (int)$id, $data);
        
        if (!$result['success']) {
            $this->error($result['message'], 404);
            return;
        }

        $this->success($result);
    }

    /**
     * حذف حساب اجتماعی
     * DELETE /api/v1/social/accounts/{id}
     */
    public function deleteAccount(string $id): void
    {
        $user = $this->currentUser();
        $result = $this->service->deleteUserAccount($user->id, (int)$id);
        
        if (!$result['success']) {
            $this->error($result['message'], 404);
            return;
        }

        $this->success($result);
    }

    // ═════════════════════════════════════════════════════════════
    // ADVERTISEMENTS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست تبلیغات کاربر
     * GET /api/v1/social/ads
     */
    public function myAds(): void
    {
        $user = $this->currentUser();
        [$page, $perPage, $offset] = $this->paginationParams();
        [$ads, $total] = $this->service->getUserAds($user->id, $perPage, $offset);
        $this->paginated($ads, $total, $page, $perPage);
    }

    /**
     * ایجاد تبلیغ جدید
     * POST /api/v1/social/ads
     */
    public function createAd(): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();

        $result = $this->service->createUserAd($user->id, $data);

        if (!$result['success']) {
            $this->validationError(['ad' => $result['message']]);
            return;
        }

        $this->success($result, 'تبلیغ ایجاد شد', 201);
    }

    /**
     * نمایش تبلیغ
     * GET /api/v1/social/ads/{id}
     */
    public function showAd(string $id): void
    {
        $user = $this->currentUser();
        $ad = $this->service->getAdById($user->id, (int)$id);
        
        if (!$ad) {
            $this->error('تبلیغ پیدا نشد', 404);
            return;
        }

        $this->success($ad);
    }

    /**
     * توقف موقت تبلیغ
     * POST /api/v1/social/ads/{id}/pause
     */
    public function pauseAd(string $id): void
    {
        $user = $this->currentUser();
        $result = $this->service->pauseUserAd($user->id, (int)$id);
        
        if (!$result['success']) {
            $this->error($result['message'], 404);
            return;
        }

        $this->success($result);
    }

    /**
     * از سر گیری تبلیغ
     * POST /api/v1/social/ads/{id}/resume
     */
    public function resumeAd(string $id): void
    {
        $user = $this->currentUser();
        $result = $this->service->resumeUserAd($user->id, (int)$id);
        
        if (!$result['success']) {
            $this->error($result['message'], 404);
            return;
        }

        $this->success($result);
    }

    /**
     * لغو تبلیغ
     * POST /api/v1/social/ads/{id}/cancel
     */
    public function cancelAd(string $id): void
    {
        $user = $this->currentUser();
        $result = $this->service->cancelUserAd($user->id, (int)$id);
        
        if (!$result['success']) {
            $this->error($result['message'], 404);
            return;
        }

        $this->success($result);
    }

    // ═════════════════════════════════════════════════════════════
    // TASKS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست وظایف موجود برای کاربر
     * GET /api/v1/social/tasks
     */
    public function tasks(): void
    {
        $user = $this->currentUser();
        [$page, $perPage, $offset] = $this->paginationParams();
        [$tasks, $total] = $this->service->getAvailableTasksForExecutor($perPage, $offset);
        $this->paginated($tasks, $total, $page, $perPage);
    }

    /**
     * شروع اجرای وظیفه
     * POST /api/v1/social/tasks/{id}/start
     */
    public function startTask(string $id): void
    {
        $user = $this->currentUser();
        $result = $this->service->startTask($user->id, (int)$id);
        $this->success($result);
    }

    /**
     * ارسال نتیجه وظیفه
     * POST /api/v1/social/tasks/{id}/submit
     */
    public function submitTask(string $id): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();

        $result = $this->service->submitTask($user->id, (int)$id, $data);
        $this->success($result);
    }

    /**
     * تاریخچه وظایف کاربر
     * GET /api/v1/social/tasks/history
     */
    public function history(): void
    {
        $user = $this->currentUser();
        [$page, $perPage, $offset] = $this->paginationParams();
        [$history, $total] = $this->service->getUserExecutionHistory($user->id, $perPage, $offset);
        $this->paginated($history, $total, $page, $perPage);
    }

    // ═════════════════════════════════════════════════════════════
    // DISPUTES
    // ═════════════════════════════════════════════════════════════

    /**
     * باز کردن dispute برای اجرای وظیفه
     * POST /api/v1/social/executions/{id}/dispute
     */
    public function openDispute(string $id): void
    {
        $user = $this->currentUser();
        $data = $this->request->body();
        $result = $this->service->openDispute($user->id, (int)$id, $data);
        $this->success($result);
    }

    /**
     * لیست disputeهای کاربر
     * GET /api/v1/social/disputes
     */
    public function disputes(): void
    {
        $user = $this->currentUser();
        [$page, $perPage, $offset] = $this->paginationParams();
        [$disputes, $total] = $this->service->getUserDisputes($user->id, $perPage, $offset);
        $this->paginated($disputes, $total, $page, $perPage);
    }

    // ═════════════════════════════════════════════════════════════
    // LEGACY ENDPOINTS (برای سازگاری عقب‌رو)
    // ═════════════════════════════════════════════════════════════

    // ─────────────────────────────────────────────────────────────
    // ثبت behavior signals (موبایل در حین انجام)
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/social-tasks/behavior
     * Body: {
     *   execution_id: int,
     *   signals: {
     *     tap_count, swipe_count, scroll_count, touch_pauses,
     *     touch_timing_variance, scroll_speed_variance, scroll_pauses,
     *     session_duration, active_time, reconnect_count,
     *     app_blur_count, max_blur_duration,
     *     hesitation_count, avg_action_delay_ms, natural_delay_count
     *   }
     * }
     */
    public function recordBehavior(): void
    {
        $user = $this->currentUser();
        $body = $this->request->body();
        $executionId = (int)($body['execution_id'] ?? 0);
        $signals = (array)($body['signals'] ?? []);

        if (!$executionId) {
            $this->error('execution_id الزامی است', 400);
        }

        // فقط فیلدهای مجاز
        $allowedSignals = [
            'tap_count', 'swipe_count', 'scroll_count', 'touch_pauses',
            'touch_timing_variance', 'scroll_speed_variance', 'scroll_pauses',
            'session_duration', 'active_time', 'reconnect_count',
            'app_blur_count', 'max_blur_duration',
            'hesitation_count', 'avg_action_delay_ms', 'natural_delay_count',
        ];
        $filtered = array_intersect_key($signals, array_flip($allowedSignals));

        $success = $this->service->recordBehaviorSignals($executionId, $user->id, $filtered);

        $this->success(['success' => $success]);
    }

    // ─────────────────────────────────────────────────────────────
    // Camera Verification Signal
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/social-tasks/camera-verify
     *
     * عکس هرگز ذخیره یا ارسال نمی‌شود.
     * موبایل نتیجه پردازش ML محلی را به صورت امتیاز ارسال می‌کند.
     *
     * Body: {
     *   execution_id: int,
     *   camera_score: int (0–100 — نتیجه ML محلی),
     *   task_type: string,
     *   verified_signals: string[] (مثلاً ['follow_button_visible','username_match'])
     * }
     */
    public function cameraVerify(): void
    {
        $user = $this->currentUser();
        $body = $this->request->body();

        $executionId     = (int)($body['execution_id'] ?? 0);
        $cameraScore     = (int)($body['camera_score'] ?? 0);
        $verifiedSignals = (array)($body['verified_signals'] ?? []);

        if (!$executionId) {
            $this->error('execution_id الزامی است', 400);
        }

        // Camera score فقط یک سیگنال است — ذخیره در behavior_data
        $signals = [
            'camera_score'      => max(0, min(100, $cameraScore)),
            'camera_signals'    => $verifiedSignals,
            'camera_verified_at'=> time(),
        ];

        $this->service->recordBehaviorSignals($executionId, $user->id, $signals);

        $this->success([
            'camera_score' => $cameraScore,
            'message'      => 'سیگنال دوربین دریافت شد',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // وضعیت Trust کاربر
    // ─────────────────────────────────────────────────────────────

    /**
     * GET /api/social-tasks/trust-status
     */
    public function trustStatus(): void
    {
        $user = $this->currentUser();

        $userObj = $this->userService->findById($user->id);
        $trust   = $userObj ? $this->trust->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;
        $weekly  = []; // To be implemented with new score analytics

        $this->success([
            'trust_score'  => $trust,
            'weekly'       => $weekly,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────
}
