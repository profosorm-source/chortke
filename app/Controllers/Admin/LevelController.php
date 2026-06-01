<?php

namespace App\Controllers\Admin;

use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use App\Services\User\UserLevelService;
use App\Controllers\Admin\BaseAdminController;
use App\Contracts\WalletServiceInterface;

class LevelController extends BaseAdminController
{
    private \App\Services\Shared\ReferralService $referralService;
    private WalletServiceInterface $walletService;
    private \App\Services\User\UserLevelService $userLevelService;
    private \App\Models\UserLevelHistory $userLevelHistoryModel;
    private \App\Models\UserLevel $userLevelModel;
    private \App\Services\User\UserService $userService;
    public function __construct(
        \App\Models\UserLevel $userLevelModel,
        \App\Models\UserLevelHistory $userLevelHistoryModel,
        \App\Services\User\UserLevelService $userLevelService,
        WalletServiceInterface $walletService,
        \App\Services\Shared\ReferralService $referralService,
        \App\Services\User\UserService $userService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->userLevelModel = $userLevelModel;
        $this->userLevelHistoryModel = $userLevelHistoryModel;
        $this->userLevelService = $userLevelService;
        $this->walletService = $walletService;
        $this->referralService = $referralService;
        $this->userService = $userService;
    }

    /**
     * لیست سطوح + آمار
     */
    public function index()
    {

        $levelModel = $this->userLevelModel;
        $levels = $levelModel->all(false);
        $userCounts = $levelModel->getUserCountPerLevel();

        $this->logger->activity('levels.view', 'مشاهده لیست سطوح', user_id(), []);

        return view('admin.levels.index', [
            'levels' => $levels,
            'userCounts' => $userCounts,
        ]);
    }

    /**
     * فرم ویرایش سطح
     */
    public function edit()
    {

                $id = (int) $this->request->param('id');

        $levelModel = $this->userLevelModel;
        $level = $levelModel->find($id);

        if (!$level) {
            \http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        return view('admin.levels.edit', ['level' => $level]);
    }

    /**
     * ذخیره ویرایش
     */
    public function update()
    {

                        
        $id = (int) $this->request->param('id');



        $validator = $this->validatorFactory()->make($this->request->all(), [
            'name' => 'required|min:2|max:50',
            'min_active_days' => 'required|numeric',
            'min_completed_tasks' => 'required|numeric',
            'min_total_earning' => 'required|numeric',
            'purchase_price_irt' => 'required|numeric',
            'earning_bonus_percent' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'خطا');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/levels/' . $id . '/edit'));
        }

        $data = $validator->data();
        $levelModel = $this->userLevelModel;

        $levelModel->update($id, [
            'name' => $data['name'],
            'icon' => $this->request->post('icon') ?? 'workspace_premium',
            'color' => $this->request->post('color') ?? '#c0c0c0',
            'min_active_days' => (int) $data['min_active_days'],
            'min_completed_tasks' => (int) $data['min_completed_tasks'],
            'min_total_earning' => (float) $data['min_total_earning'],
            'min_total_earning_usdt' => (float) ($this->request->post('min_total_earning_usdt') ?? 0),
            'purchase_price_irt' => (float) $data['purchase_price_irt'],
            'purchase_price_usdt' => (float) ($this->request->post('purchase_price_usdt') ?? 0),
            'purchase_duration_days' => (int) ($this->request->post('purchase_duration_days') ?? 30),
            'earning_bonus_percent' => (float) $data['earning_bonus_percent'],
            'referral_bonus_percent' => (float) ($this->request->post('referral_bonus_percent') ?? 0),
            'daily_task_limit_bonus' => (int) ($this->request->post('daily_task_limit_bonus') ?? 0),
            'withdrawal_limit_bonus' => (float) ($this->request->post('withdrawal_limit_bonus') ?? 0),
            'priority_support' => $this->request->post('priority_support') ? 1 : 0,
            'special_badge' => $this->request->post('special_badge') ? 1 : 0,
            'is_active' => $this->request->post('is_active') ? 1 : 0,
        ]);

        $this->logger->activity('levels.update', 'ویرایش سطح', user_id(), ['level_id' => $id, 'name' => $data['name']]);

        $this->session->setFlash('success', 'سطح «' . e($data['name']) . '» بروزرسانی شد.');
        return redirect(url('/admin/levels'));
    }

    /**
     * تغییر سطح کاربر توسط ادمین (Ajax)
     */
    public function changeUserLevel()
    {
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($body['user_id'] ?? 0);
        $newLevel = $body['level'] ?? '';
        $reason = $body['reason'] ?? 'تغییر توسط مدیر';

        if (!$userId || !$newLevel) {
            $this->response->json(['success' => false, 'message' => 'اطلاعات ناقص'], 422);
            return;
        }

        $currentAdminId = $this->userId();

        // Prevent self-modification
        if ($userId === $currentAdminId) {
            $this->response->json(['success' => false, 'message' => 'نمیتوانید سطح خودتان را تغییر دهید'], 403);
            return;
        }

        // Prevent modifying other admins (unless super admin / permitted)
        $targetUser = $this->userService->find($userId);
        if ($targetUser && in_array($targetUser->role, ['admin', 'super_admin'])) {
            $currentAdminUser = $this->userService->find($currentAdminId);
            if (!$currentAdminUser || !$this->policyService->can('admin.level.change_admin_level', $currentAdminUser)) {
                $this->response->json(['success' => false, 'message' => 'شما اجازه تغییر سطح ادمینها را ندارید'], 403);
                return;
            }
        }

        $levelService = $this->userLevelService;
        $changed = $levelService->adminChangeLevel($userId, $newLevel, $reason);

        if (!$changed) {
            $this->response->json(['success' => false, 'message' => 'خطا در تغییر سطح'], 500);
            return;
        }

        $this->logger->activity('levels.admin_change', 'تغییر سطح کاربر', user_id(), [
            'user_id' => $userId,
            'new_level' => $newLevel,
            'reason' => $reason,
        ]);

        $this->response->json([
            'success' => true,
            'message' => 'سطح کاربر با موفقیت تغییر یافت.',
        ]);
    }

    /**
     * لیست تاریخچه تغییرات سطح
     */
    public function history()
    {

                $historyModel = $this->userLevelHistoryModel;
        $levelModel = $this->userLevelModel;

        $filters = [
            'change_type' => $this->request->get('change_type'),
            'to_level' => $this->request->get('to_level'),
            'user_id' => $this->request->get('user_id'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $items = $historyModel->adminList($filters, $limit, $offset);
        $total = $historyModel->adminCount($filters);
        $levels = $levelModel->all(false);

        return view('admin.levels.user-levels', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
            'levels' => $levels,
        ]);
    }

    /**
     * فرم ایجاد سطح جدید
     */
    public function create()
    {

        return view('admin.levels.create', []);
    }

    /**
     * ذخیره سطح جدید
     */
    public function store()
    {



        $validator = $this->validatorFactory()->make($this->request->all(), [
            'name'                  => 'required|min:2|max:50',
            'slug'                  => 'required|min:2|max:50',
            'min_active_days'       => 'required|numeric',
            'min_completed_tasks'   => 'required|numeric',
            'min_total_earning'     => 'required|numeric',
            'purchase_price_irt'    => 'required|numeric',
            'earning_bonus_percent' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'خطا در اعتبارسنجی');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/levels/create'));
        }

        $data = $validator->data();
        $levelModel = $this->userLevelModel;

        // slug را sanitize کنیم
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($data['slug']));
        if (empty($slug)) {
            $this->session->setFlash('error', 'slug نامعتبر است. فقط حروف انگلیسی کوچک، عدد، خط تیره و زیرخط مجاز است.');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/levels/create'));
        }

        // بررسی تکراری نبودن slug
        if ($levelModel->slugExists($slug)) {
            $this->session->setFlash('error', 'این slug قبلاً استفاده شده است.');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/levels/create'));
        }

        $sortOrder = (int) ($this->request->post('sort_order') ?: ($levelModel->getMaxSortOrder() + 10));

        $level = $levelModel->create([
            'name'                    => $data['name'],
            'slug'                    => $slug,
            'icon'                    => $this->request->post('icon') ?? 'workspace_premium',
            'color'                   => $this->request->post('color') ?? '#c0c0c0',
            'sort_order'              => $sortOrder,
            'min_active_days'         => (int) $data['min_active_days'],
            'min_completed_tasks'     => (int) $data['min_completed_tasks'],
            'min_total_earning'       => (float) $data['min_total_earning'],
            'min_total_earning_usdt'  => (float) ($this->request->post('min_total_earning_usdt') ?? 0),
            'purchase_price_irt'      => (float) $data['purchase_price_irt'],
            'purchase_price_usdt'     => (float) ($this->request->post('purchase_price_usdt') ?? 0),
            'purchase_duration_days'  => (int) ($this->request->post('purchase_duration_days') ?? 30),
            'earning_bonus_percent'   => (float) $data['earning_bonus_percent'],
            'referral_bonus_percent'  => (float) ($this->request->post('referral_bonus_percent') ?? 0),
            'daily_task_limit_bonus'  => (int) ($this->request->post('daily_task_limit_bonus') ?? 0),
            'withdrawal_limit_bonus'  => (float) ($this->request->post('withdrawal_limit_bonus') ?? 0),
            'priority_support'        => $this->request->post('priority_support') ? 1 : 0,
            'special_badge'           => $this->request->post('special_badge') ? 1 : 0,
            'is_active'               => $this->request->post('is_active') ? 1 : 0,
        ]);

        if (!$level) {
            $this->session->setFlash('error', 'خطا در ایجاد سطح جدید.');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/levels/create'));
        }

        $this->logger->activity('levels.create', 'ایجاد سطح جدید', user_id(), ['level_id' => $level->id, 'name' => $data['name'], 'slug' => $slug]);

        $this->session->setFlash('success', 'سطح «' . e($data['name']) . '» با موفقیت ایجاد شد.');
        return redirect(url('/admin/levels'));
    }

    /**
     * حذف سطح (Ajax)
     */
    public function destroy()
    {

        $id = (int) $this->request->param('id');
        $levelModel = $this->userLevelModel;
        $level = $levelModel->find($id);

        if (!$level) {
            $this->response->json(['success' => false, 'message' => 'سطح یافت نشد.'], 404);
            return;
        }

        // سطح پیش‌فرض (برنز) قابل حذف نیست
        if ($level->slug === 'bronze') {
            $this->response->json(['success' => false, 'message' => 'سطح پیش‌فرض (برنز) قابل حذف نیست.'], 403);
            return;
        }

        // اگر کاربری در این سطح هست، اجازه حذف نداریم
        $userCounts = $levelModel->getUserCountPerLevel();
        if (($userCounts[$level->slug] ?? 0) > 0) {
            $this->response->json([
                'success' => false,
                'message' => 'این سطح دارای ' . ($userCounts[$level->slug]) . ' کاربر فعال است و قابل حذف نیست.',
            ], 409);
            return;
        }

        $deleted = $levelModel->delete($id);

        if (!$deleted) {
            $this->response->json(['success' => false, 'message' => 'خطا در حذف سطح.'], 500);
            return;
        }

        $this->logger->activity('levels.delete', 'حذف سطح', user_id(), ['level_id' => $id, 'name' => $level->name, 'slug' => $level->slug]);

        $this->response->json(['success' => true, 'message' => 'سطح «' . $level->name . '» حذف شد.']);
    }

}