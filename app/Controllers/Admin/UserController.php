<?php

namespace App\Controllers\Admin;

use App\Services\User\UserService;
use App\Contracts\ValidatorFactoryInterface;
use App\Controllers\Admin\BaseAdminController;

class UserController extends BaseAdminController
{
    private UserService $userService;
    private \App\Services\User\AccountDeletionService $deletionService;
    private ValidatorFactoryInterface $validatorFactory;

    public function __construct(
        UserService $userService,
        \App\Services\User\AccountDeletionService $deletionService,
        ValidatorFactoryInterface $validatorFactory
    , ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->deletionService = $deletionService;
        $this->validatorFactory = $validatorFactory;
    }

    /**
     * نمایش لیست کاربران
     */
    public function index(): void
    {
        $search = (string)$this->request->get('search', '');
        $role = (string)$this->request->get('role', '');
        $status = (string)$this->request->get('status', '');
        $page = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

    $filters = array_filter([
        'search' => $search,
        'role'   => $role,
        'status' => $status,
    ], fn($v) => $v !== '');

    $users      = $this->userService->searchWithFilters($filters, $perPage, $offset);
    $total      = $this->userService->countWithFilters($filters);
    $totalPages = (int)ceil($total / $perPage);
    $userStats  = $this->userService->getAdminStats();

    if (!$userStats) {
        $userStats = (object)[
            'total_count' => 0,
            'active_count' => 0,
            'suspended_count' => 0,
            'banned_count' => 0,
            'deleted_count' => 0,
        ];
    } elseif (is_array($userStats)) {
        $userStats = (object)$userStats;
    }

        $this->view('admin.users.index', [
            'users' => $users,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'search' => $search,
            'roleFilter' => $role,
            'statusFilter' => $status,
            'userStats' => $userStats
        ]);
    }

    public function create(): void
    {
        $this->view('admin.users.create');
    }

    public function store(): void
    {
        // Create validation is kept local to avoid depending on removed/legacy FormRequest classes.
        $data = $this->request->body() ?? [];
        $validator = $this->validatorFactory->make($data, [
            'full_name' => 'required|min:3|max:100',
            'email'     => 'required|email',
            'password'  => 'required|min:8',
            'role'      => 'required|in:user,admin,support,super_admin',
            'status'    => 'required|in:active,inactive,suspended,banned',
        ]);
        if ($validator->fails()) {
            $this->response->json(['success' => false, 'errors' => $validator->errors()], 422);
            return;
        }
        $validated = (object)$validator->data();

        $currentAdmin = $this->userService->find($this->userId());
        if (!$currentAdmin) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
            return;
        }

        $hierarchy = ['user' => 0, 'admin' => 1, 'super_admin' => 2];
        $adminRoleLevel = $hierarchy[$currentAdmin->role ?? 'user'] ?? 0;
        $newRole = $validated->role ?? 'user';
        $newRoleLevel = $hierarchy[$newRole] ?? 0;

        if ($newRoleLevel > $adminRoleLevel) {
            $this->response->json([
                'success' => false,
                'message' => 'شما نمی‌توانید کاربر با سطحی بالاتر از خود ایجاد کنید.'
            ], 403);
            return;
        }

        $existingUser = $this->userService->findByEmail($validated->email);
        if ($existingUser) {
            $this->response->json([
                'success' => false,
                'errors' => ['email' => ['این ایمیل قبلاً ثبت شده است']]
            ], 422);
            return;
        }

        // ✅ Check mobile uniqueness
        if (!empty($validated->mobile)) {
            $existingMobile = $this->userService->findByMobile($validated->mobile);
            if ($existingMobile) {
                $this->response->json([
                    'success' => false,
                    'errors' => ['mobile' => ['این شماره موبایل قبلاً ثبت شده است']]
                ], 422);
                return;
            }
        }

        $userId = $this->userService->register([
            'full_name' => $validated->full_name,
            'email' => $validated->email,
            'password' => $validated->password,
            'role' => $validated->role,
            'status' => $validated->status,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        if ($userId) {
            $this->response->json([
                'success' => true,
                'message' => 'کاربر با موفقیت ایجاد شد',
                'redirect' => url('/admin/users')
            ]);
        } else {
            $this->response->json([
                'success' => false,
                'message' => 'خطا در ایجاد کاربر'
            ], 500);
        }
    }

    public function edit(int $id): void
    {
        $user = $this->userService->find($id);

        if (!$user) {
            $this->response->redirect(url('admin/users'));
            return;
        }

        $this->view('admin.users.edit', ['user' => $user]);
    }

      /**
     * 🔄 تغییر از JSON به Redirect + Flash
     * چون این یک فرم با صفحه است
     */
    /**
  * به‌روزرسانی کاربر
  */
public function update(int $id): void
{
    $user = $this->userService->find($id);
    
    if (!$user) {
        $this->response->json([
            'success' => false,
            'message' => 'کاربر یافت نشد'
        ], 404);
        return;
    }

    // دریافت داده‌ها از بدنه درخواست
    $data = $this->request->body() ?? [];

    // ✅ اعتبارسنجی متمرکز با استفاده از FormRequest
    $validated = $this->validateRequest(\App\Validators\Requests\UserUpdateRequest::class, $data);

    // Role Hierarchy and Self-Escalation Check (CRIT-05)
    $currentAdmin = $this->userService->find($this->userId());
    if (!$currentAdmin) {
        $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
        return;
    }

    $hierarchy = ['user' => 0, 'admin' => 1, 'super_admin' => 2];
    $adminRoleLevel = $hierarchy[$currentAdmin->role ?? 'user'] ?? 0;
    $targetUserRoleLevel = $hierarchy[$user->role ?? 'user'] ?? 0;

    // Non-super_admins cannot edit other admins
    if ($adminRoleLevel < 2 && $targetUserRoleLevel >= 1 && $user->id !== $currentAdmin->id) {
        $this->response->json([
            'success' => false,
            'message' => 'شما مجاز به ویرایش سایر مدیران نیستید.'
        ], 403);
        return;
    }

    // Cannot assign a role higher than the admin's current role
    $newRole = $validated->role ?? $user->role;
    $newRoleLevel = $hierarchy[$newRole] ?? 0;
    if ($newRoleLevel > $adminRoleLevel) {
        $this->response->json([
            'success' => false,
            'message' => 'شما نمی‌توانید سطحی بالاتر از سطح خود تخصیص دهید.'
        ], 403);
        return;
    }

    // State Machine validation for status transitions (CRIT-08)
    if (isset($validated->status)) {
        if (!$this->validateStatusTransition($user->status ?? 'active', $validated->status)) {
            $this->response->json([
                'success' => false,
                'message' => 'تغییر وضعیت غیرمجاز است.'
            ], 400);
            return;
        }
    }

    // استفاده از متد جامع سرویس برای مدیریت ایمیل تکراری و هش کردن پسورد
    $result = $this->userService->updateUser($id, $validated);

    if (!empty($result['success'])) {
        \App\Middleware\PermissionMiddleware::clearCache($id);
        $this->response->json([
            'success' => true,
            'message' => $result['message'] ?? 'کاربر با موفقیت به‌روزرسانی شد',
            'redirect' => url('/admin/users')
        ]);
    } else {
        // اگر ارور مربوط به ولیدیشن تکراری بودن باشد
        $statusCode = !empty($result['errors']) ? 422 : 500;
        $this->response->json([
            'success' => false,
            'message' => $result['message'] ?? 'خطا در به‌روزرسانی کاربر',
            'errors' => $result['errors'] ?? []
        ], $statusCode);
    }
}

    /**
     * حذف نرم (Soft Delete)
     */
    public function delete(int $id): void
    {
        $user = $this->userService->find($id);
        if (!$user) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if ($id === $this->userId()) {
            $this->jsonError('شما نمی‌توانید خودتان را حذف کنید', [], 403);
            return;
        }

        // Prevent non-super_admins from deleting other admins
        $currentAdmin = $this->userService->find($this->userId());
        $adminRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$currentAdmin->role ?? 'user'] ?? 0;
        $targetUserRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$user->role ?? 'user'] ?? 0;
        if ($adminRoleLevel < 2 && $targetUserRoleLevel >= 1) {
            $this->response->json(['success' => false, 'message' => 'شما مجاز به حذف سایر مدیران نیستید.'], 403);
            return;
        }

        // ✅ Use AccountDeletionService for consistent deletion
        $result = $this->deletionService->deleteUserAccount($id, 'Deleted by Admin');

        if ($result) {
            $this->response->json(['success' => true, 'message' => 'کاربر با موفقیت حذف شد']);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در حذف کاربر یا کاربر دارای موجودی است'], 500);
        }
    }
	/**
     * بن/فعال‌سازی کاربر
     */
    public function ban(int $id): void
    {
        $currentAdminId = $this->userId();
        if ($id === $currentAdminId) {
            $this->jsonError('شما نمی‌توانید خودتان را مسدود کنید', [], 403);
            return;
        }

        $user = $this->userService->find($id);
        if (!$user) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        // اگر حذف نرم شده باشد، اجازه تغییر وضعیت نده
        if (!empty($user->deleted_at)) {
            $this->response->json(['success' => false, 'message' => 'این کاربر حذف شده است'], 400);
            return;
        }

        // Prevent non-super_admins from banning other admins
        $currentAdmin = $this->userService->find($currentAdminId);
        $adminRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$currentAdmin->role ?? 'user'] ?? 0;
        $targetUserRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$user->role ?? 'user'] ?? 0;
        if ($adminRoleLevel < 2 && $targetUserRoleLevel >= 1) {
            $this->response->json(['success' => false, 'message' => 'شما مجاز به تغییر وضعیت سایر مدیران نیستید.'], 403);
            return;
        }

        if ($user->status === 'banned') {
            $newStatus = 'active';
        } else {
            $newStatus = 'banned';
        }

        // State Machine transition check
        if (!$this->validateStatusTransition($user->status ?? 'active', $newStatus)) {
            $this->response->json(['success' => false, 'message' => 'تغییر وضعیت به مسدود غیرمجاز است.'], 400);
            return;
        }

        if ($newStatus === 'active') {
            $ok = $this->userService->unbanUser($id);
        } else {
            $ok = $this->userService->banUser($id, 'Suspended by Admin');
        }

        if ($ok) {
            // لاگ امنیتی (اختیاری)
            $this->auditLog(
                'user.ban.toggle',
                'user',
                $id,
                ['status' => $user->status ?? 'active'],
                ['status' => $newStatus]
            );

            $this->response->json([
                'success' => true,
                'message' => $newStatus === 'banned' ? 'کاربر با موفقیت بن شد' : 'کاربر از حالت بن خارج شد',
                'newStatus' => $newStatus
            ]);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در تغییر وضعیت کاربر'], 500);
        }
    }

    /**
     * تعلیق کاربر
     */
    public function suspend(int $id): void
    {
        if ($id === $this->userId()) {
            $this->jsonError('شما نمی‌توانید خودتان را تعلیق کنید', [], 403);
            return;
        }

        $user = $this->userService->find($id);
        if (!$user) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if (!empty($user->deleted_at)) {
            $this->response->json(['success' => false, 'message' => 'این کاربر حذف شده است'], 400);
            return;
        }

        if ($user->status === 'banned') {
            $this->response->json(['success' => false, 'message' => 'کاربر بن است؛ ابتدا از بن خارج کنید'], 400);
            return;
        }

        // Prevent non-super_admins from suspending other admins
        $currentAdmin = $this->userService->find($this->userId());
        $adminRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$currentAdmin->role ?? 'user'] ?? 0;
        $targetUserRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$user->role ?? 'user'] ?? 0;
        if ($adminRoleLevel < 2 && $targetUserRoleLevel >= 1) {
            $this->response->json(['success' => false, 'message' => 'شما مجاز به تعلیق سایر مدیران نیستید.'], 403);
            return;
        }

        $newStatus = ($user->status === 'suspended') ? 'active' : 'suspended';

        // State Machine transition check
        if (!$this->validateStatusTransition($user->status ?? 'active', $newStatus)) {
            $this->response->json(['success' => false, 'message' => 'تغییر وضعیت به تعلیق غیرمجاز است.'], 400);
            return;
        }

        $ok = $this->userService->update($id, [
            'status' => $newStatus,
            'updated_at' => \date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->auditLog(
                'user.suspend.toggle',
                'user',
                $id,
                ['status' => $user->status ?? 'active'],
                ['status' => $newStatus]
            );

            $this->response->json([
                'success' => true,
                'message' => $newStatus === 'suspended' ? 'کاربر تعلیق شد' : 'تعلیق برداشته شد',
                'newStatus' => $newStatus
            ]);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در تغییر وضعیت'], 500);
        }
    }
}
