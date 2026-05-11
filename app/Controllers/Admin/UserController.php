<?php

namespace App\Controllers\Admin;

use App\Services\User\UserService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class UserController extends BaseAdminController
{
    private UserService $userService;

    public function __construct(
        UserService $userService
    )
    {
        parent::__construct();
        $this->userService = $userService;
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
        $validator = new Validator($this->request->all());
        $validator->validate([
            'full_name' => 'required|min:3|max:100',
            'email' => 'required|email',
            'password' => 'required|min:8',
            'role' => 'required|in:user,admin,support',
            'status' => 'required|in:active,inactive,suspended,banned'
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
            return;
        }

        $data = $validator->validated();
        $existingUser = $this->userService->findByEmail($data->email);
        if ($existingUser) {
            $this->response->json([
                'success' => false,
                'errors' => ['email' => ['این ایمیل قبلاً ثبت شده است']]
            ], 422);
            return;
        }

        $userId = $this->userService->register([
            'full_name' => $data->full_name,
            'email' => $data->email,
            'password' => $data->password,
            'role' => $data->role,
            'status' => $data->status,
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

    // قوانین اعتبارسنجی
    $rules = [
        'full_name' => 'required|min:3|max:100',
        'email' => 'required|email',
        'role' => 'required|in:user,admin,support',
        'status' => 'required|in:active,inactive,suspended,banned'
    ];

    if (!empty($data['password'])) {
        $rules['password'] = 'min:8';
    }

    $validator = new \Core\Validator($data, $rules);
    $validator->validate();

    if ($validator->fails()) {
        $this->response->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
        return;
    }

    // بررسی ایمیل تکراری
    $existingEmail = $this->userService->findByEmail($data['email']);
    $existingUser = ($existingEmail && $existingEmail->id != $id) ? $existingEmail : null;

    if ($existingUser) {
        $this->response->json([
            'success' => false,
            'errors' => ['email' => ['این ایمیل قبلاً ثبت شده است']]
        ], 422);
        return;
    }

    // آماده‌سازی داده‌ها برای بروزرسانی
    $updateData = [
        'full_name' => $data['full_name'],
        'email' => $data['email'],
        'role' => $data['role'],
        'status' => $data['status'],
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if (!empty($data['password'])) {
        $updateData['password'] = hash_password($data['password']);
    }

$result = $this->userService->update($id, $updateData);

    if ($result) {
        \App\Middleware\PermissionMiddleware::clearCache();
        $this->response->json([
            'success' => true,
            'message' => 'کاربر با موفقیت به‌روزرسانی شد',
            'redirect' => url('/admin/users')
        ]);
    } else {
        $this->response->json([
            'success' => false,
            'message' => 'خطا در به‌روزرسانی کاربر'
        ], 500);
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

        $result = $this->userService->update($id, [
            'deleted_at' => date('Y-m-d H:i:s'),
            'status' => 'inactive'
        ]);

        if ($result) {
            $this->response->json(['success' => true, 'message' => 'کاربر با موفقیت حذف شد']);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در حذف کاربر'], 500);
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

    if ($user->status === 'banned') {
        $ok = $this->userService->unbanUser($id);
        $newStatus = 'active';
    } else {
        $ok = $this->userService->banUser($id, 'Suspended by Admin');
        $newStatus = 'banned';
    }

    if ($ok) {
        // لاگ امنیتی (اختیاری)
        $this->logger->activity(
    'user.ban.toggle',
    'تغییر وضعیت بن کاربر',
    $currentAdminId,
    ['target_user_id' => $id, 'new_status' => $newStatus]
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

    $newStatus = ($user->status === 'suspended') ? 'active' : 'suspended';

    $ok = $this->userService->update($id, [
        'status' => $newStatus,
        'updated_at' => \date('Y-m-d H:i:s')
    ]);

    if ($ok) {
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
