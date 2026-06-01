<?php

namespace App\Controllers\Admin;

use App\Models\Role;
use App\Models\Permission;
use App\Middleware\PermissionMiddleware;
use App\Controllers\Admin\BaseAdminController;

class RoleController extends BaseAdminController
{
    private \App\Models\Role $roleModel;
    private \App\Models\Permission $permissionModel;
    public function __construct(
        \App\Models\Permission $permissionModel,
        \App\Models\Role $roleModel, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->permissionModel = $permissionModel;
        $this->roleModel = $roleModel;
    }

    /**
     * لیست نقش‌ها
     */
    public function index()
    {
        $roles = $this->roleModel->allRoles(false); // شامل غیرفعال‌ها و لود خودکار user_count
        
        $this->logger->activity('roles.view', 'مشاهده لیست نقش‌ها', user_id(), []);
        
        return view('admin.roles.index', [
            'roles' => $roles,
        ]);
    }
    
    /**
     * فرم ایجاد نقش
     */
    public function create()
    {
        $permModel = $this->permissionModel;
        $groupedPermissions = $permModel->allGrouped();
        $groupLabels = $permModel->groupLabels();
        
        return view('admin.roles.create', [
            'groupedPermissions' => $groupedPermissions,
            'groupLabels' => $groupLabels,
        ]);
    }
    
    /**
     * ذخیره نقش جدید
     */
    public function store()
    {

        
        $validator = $this->validatorFactory()->make($this->request->all(), [
            'name'        => 'required|min:2|max:50',
            'slug'        => 'required|min:2|max:50|alpha_dash',
            'description' => 'max:255',
        ]);
        
        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'خطا در اعتبارسنجی');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/roles/create'));
        }
        
        $data = $validator->data();
        $roleModel = $this->roleModel;
        
        // بررسی تکراری نبودن slug
        if ($roleModel->slugExists($data['slug'])) {
            $this->session->setFlash('error', 'این شناسه (slug) قبلاً استفاده شده است.');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/roles/create'));
        }

        $role = $roleModel->create([
            'name'        => $data['name'],
            'slug'        => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_system'   => 0,
            'is_active'   => 1,
        ]);
        
        if (!$role) {
            $this->session->setFlash('error', 'خطا در ایجاد نقش. لطفاً دوباره تلاش کنید.');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/roles/create'));
        }
        
        // همگام‌سازی دسترسی‌ها
        $permissionIds = $this->request->post('permissions') ?? [];
        if (!empty($permissionIds)) {
            $roleModel->syncPermissions($role->id, $permissionIds);
        }
        
        $this->logger->activity('roles.create', 'ایجاد نقش جدید', user_id(), [
            'role_id'   => $role->id,
            'role_name' => $role->name,
            'role_slug' => $role->slug,
        ]);
        
        $this->session->setFlash('success', 'نقش «' . e($role->name) . '» با موفقیت ایجاد شد.');
        return redirect(url('/admin/roles'));
    }
    
    /**
     * فرم ویرایش نقش
     */
    public function edit()
    {
                $id = (int) $this->request->param('id');
        
        $roleModel = $this->roleModel;
        $role = $roleModel->find($id);
        
        if (!$role) {
            \http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }
        
        $permModel = $this->permissionModel;
        $groupedPermissions = $permModel->allGrouped();
        $groupLabels = $permModel->groupLabels();
        $rolePermissionIds = \array_map(function ($p) {
            return $p->id;
        }, $roleModel->getPermissions($id));
        
        return view('admin.roles.edit', [
            'role'                => $role,
            'groupedPermissions'  => $groupedPermissions,
            'groupLabels'         => $groupLabels,
            'rolePermissionIds'   => $rolePermissionIds,
        ]);
    }
    
    /**
     * بروزرسانی نقش
     */
    public function update()
    {
        $id = (int) $this->request->param('id');
        $roleModel = $this->roleModel;
        $role = $roleModel->find($id);
        
        if (!$role) {
            $this->session->setFlash('error', 'نقش مورد نظر یافت نشد.');
            return redirect(url('/admin/roles'));
        }
        

        
        $validator = $this->validatorFactory()->make($this->request->all(), [
            'name'        => 'required|min:2|max:50',
            'description' => 'max:255',
        ]);
        
        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'خطا در اعتبارسنجی');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/admin/roles/' . $id . '/edit'));
        }
        
        $data = $validator->data();
        
        $updateData = [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ];
        
        // فقط غیر سیستمی‌ها قابل غیرفعال‌سازی
        if (!$role->is_system) {
            $updateData['is_active'] = $this->request->post('is_active') ? 1 : 0;
        }
        
        $roleModel->update($id, $updateData);
        
        // همگام‌سازی دسترسی‌ها
        $permissionIds = $this->request->post('permissions') ?? [];
        $roleModel->syncPermissions($id, $permissionIds);
        
        // پاکسازی کش دسترسی‌ها
        $db = $this->roleModel->getDb();
        $userIds = $db->fetchAll(
            "SELECT id FROM users WHERE role_id = ? AND deleted_at IS NULL",
            [$id]
        );
        foreach ($userIds as $row) {
            PermissionMiddleware::clearCache((int)$row->id);
        }
        
        $this->logger->activity('roles.update', 'ویرایش نقش', user_id(), [
            'role_id'   => $id,
            'role_name' => $data['name'],
        ]);

        $this->session->setFlash('success', 'نقش «' . e($data['name']) . '» با موفقیت بروزرسانی شد.');
        return redirect(url('/admin/roles'));
    }
    
    /**
     * حذف نقش (Ajax)
     */
    public function delete()
    {


        $id = (int) $this->request->param('id');
        $roleModel = $this->roleModel;
        $role = $roleModel->find($id);
        
        if (!$role) {
            $this->response->json([
                'success' => false,
                'message' => 'نقش مورد نظر یافت نشد.'
            ], 404);
            return;
        }
        
        if ($role->is_system) {
            $this->response->json([
                'success' => false,
                'message' => 'نقش‌های سیستمی قابل حذف نیستند.'
            ], 403);
            return;
        }
        
        // بررسی عدم وجود کاربر با این نقش
        $userCount = $roleModel->getUserCount($id);
        if ($userCount > 0) {
            $this->response->json([
                'success' => false,
                'message' => "این نقش {$userCount} کاربر دارد. ابتدا نقش کاربران را تغییر دهید."
            ], 422);
            return;
        }
        
        $deleted = $roleModel->delete($id);
        
        if (!$deleted) {
            $this->response->json([
                'success' => false,
                'message' => 'خطا در حذف نقش.'
            ], 500);
            return;
        }
        
        $this->logger->activity('roles.delete', 'حذف نقش', user_id(), [
            'role_id'   => $id,
            'role_name' => $role->name,
            'role_slug' => $role->slug,
        ]);
        
        $this->response->json([
            'success' => true,
            'message' => 'نقش «' . $role->name . '» با موفقیت حذف شد.'
        ]);
    }
    
    /**
     * تغییر وضعیت فعال/غیرفعال (Ajax)
     */
    public function toggle()
    {
        $id = (int) $this->request->param('id');
        $roleModel = $this->roleModel;
        $role = $roleModel->find($id);
        
        if (!$role) {
            $this->response->json([
                'success' => false,
                'message' => 'نقش مورد نظر یافت نشد.'
            ], 404);
            return;
        }
        
        if ($role->is_system) {
            $this->response->json([
                'success' => false,
                'message' => 'وضعیت نقش‌های سیستمی قابل تغییر نیست.'
            ], 403);
            return;
        }
        
        $newStatus = $role->is_active ? 0 : 1;
        $roleModel->update($id, ['is_active' => $newStatus]);
        
        $db = $this->roleModel->getDb();
        $userIds = $db->fetchAll(
            "SELECT id FROM users WHERE role_id = ? AND deleted_at IS NULL",
            [$id]
        );
        foreach ($userIds as $row) {
            PermissionMiddleware::clearCache((int)$row->id);
        }
        
        $statusText = $newStatus ? 'فعال' : 'غیرفعال';
        
        $this->logger->activity('roles.toggle', "تغییر وضعیت نقش به {$statusText}", user_id(), [
            'role_id' => $id,
            'new_status' => $newStatus,
        ]);
        
        $this->response->json([
            'success' => true,
            'message' => "نقش «{$role->name}» {$statusText} شد.",
            'new_status' => $newStatus,
        ]);
    }
}