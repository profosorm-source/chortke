<?php

namespace App\Controllers\User;

use App\Services\User\UserService;
use App\Services\UploadService;
use App\Controllers\User\BaseUserController;

class ProfileController extends BaseUserController
{
    private UploadService $uploadService;
    private UserService $userService;

    public function __construct(
        UserService $userService,
        \App\Services\UploadService $uploadService)
    {
        parent::__construct();
        $this->userService = $userService;
        $this->uploadService = $uploadService;
    }

    public function index(): void
    {
        $userId = user_id();
        $user = $this->userService->findById($userId);
        
        if (!$user) {
            $this->session->setFlash('error', 'کاربر یافت نشد');
            redirect('dashboard');
        }
        
        view('user.profile', ['user' => $user]);
    }

    public function update(): void
    {
        $userId = user_id();

        // Rate Limiting - محدودیت بروزرسانی پروفایل
        try {
            rate_limit('content', 'update', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                redirect('profile');
                return;
            }
        }
        
        $fullName = $this->request->input('full_name');
        $mobile = $this->request->input('mobile');
        $nationalId = $this->request->input('national_id');
        $birthDate = $this->request->input('birth_date');
        $gender = $this->request->input('gender');
        $address = $this->request->input('address');
        
        $fullName = $fullName ? trim($fullName) : null;
        $mobile = $mobile ? trim($mobile) : null;
        $nationalId = $nationalId ? trim($nationalId) : null;
        $address = $address ? trim($address) : null;
        
        $errors = [];
        
        if (!$fullName || mb_strlen($fullName) < 3) {
            $errors[] = 'نام کامل باید حداقل 3 کاراکتر باشد';
        }
        
        if ($mobile && !preg_match('/^09[0-9]{9}$/', $mobile)) {
            $errors[] = 'شماره موبایل نامعتبر است';
        }
        
        if ($nationalId && !preg_match('/^[0-9]{10}$/', $nationalId)) {
            $errors[] = 'کد ملی باید 10 رقم باشد';
        }
        
        if ($birthDate && !strtotime($birthDate)) {
            $errors[] = 'تاریخ تولد نامعتبر است';
        }
        
        if ($gender && !in_array($gender, ['male', 'female', 'other'])) {
            $errors[] = 'جنسیت نامعتبر است';
        }
        
        if (!empty($errors)) {
            $this->session->setFlash('error', implode('<br>', $errors));
            redirect('profile');
            return;
        }
        
        $data = [
            'full_name'   => $fullName,
            'mobile'      => $mobile,
            'national_id' => $nationalId,
            'birth_date'  => $birthDate ?: null,
            'gender'      => $gender ?: null,
            'address'     => $address,
            'updated_at'  => date('Y-m-d H:i:s')
        ];
        
        $result = $this->userService->update($userId, $data);
        
        if ($result) {
            // ✅ اصلاح logger
            $this->logger->info('Profile updated', ['user_id' => $userId]);
            
            $this->session->setFlash('success', 'اطلاعات پروفایل با موفقیت بروزرسانی شد');
        } else {
            $this->session->setFlash('error', 'خطا در بروزرسانی اطلاعات');
        }
        
        redirect('profile');
    }

   public function uploadAvatar(): void
{
        $userId = user_id();

        // Rate Limiting - محدودیت آپلود آواتار
        try {
            rate_limit('upload', 'avatar', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->response->json(['success' => false, 'message' => $e->getMessage()], 429);
                return;
            }
        }

    if (!isset($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $this->response->json(['success' => false, 'message' => 'لطفاً یک تصویر انتخاب کنید'], 400);
        return;
    }

    $uploadService = $this->uploadService;

    $upload = $uploadService->upload(
        $_FILES['avatar'],
        'avatars',
        ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'],
        2097152
    );

    if (!is_array($upload) || empty($upload['success']) || empty($upload['filename']) || empty($upload['path'])) {
        $this->response->json(['success' => false, 'message' => 'خطا در آپلود تصویر'], 400);
        return;
    }

    $filename = (string) $upload['filename'];

    $oldUser = $this->userService->findById($userId);
    if ($oldUser && !empty($oldUser->avatar) && $oldUser->avatar !== 'default-avatar.png') {
        if (method_exists($uploadService, 'delete')) {
            $uploadService->delete('avatars/' . $oldUser->avatar);
        }
    }

$result = $this->userService->update($userId, [
        'avatar' => $filename,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    if (!$result) {
        $this->logger->info('Avatar update failed', ['user_id' => $userId, 'filename' => $filename]);
        $this->response->json(['success' => false, 'message' => 'خطا در ذخیره‌سازی آواتار در دیتابیس'], 500);
        return;
    }

    $this->logger->info('Avatar uploaded', ['user_id' => $userId, 'filename' => $filename]);

    $this->response->json([
        'success' => true,
        'message' => 'تصویر پروفایل با موفقیت بروزرسانی شد',
        'avatar_url' => asset('uploads/' . ltrim((string)$upload['path'], '/'))
    ]);
    return;
}

    public function deleteAvatar(): void
    {
        $userId = user_id();

        $user = $this->userService->findById($userId);

        if (!$user) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if (empty($user->avatar) || $user->avatar === 'default-avatar.png') {
            $this->response->json(['success' => false, 'message' => 'تصویر پیش‌فرض قابل حذف نیست'], 400);
            return;
        }

        $uploadService = $this->uploadService;

        if (method_exists($uploadService, 'delete')) {
            $uploadService->delete('avatars/' . $user->avatar);
        }

        $result = $this->userService->update($userId, [
            'avatar'     => 'default-avatar.png',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if (!$result) {
            $this->response->json(['success' => false, 'message' => 'خطا در بروزرسانی دیتابیس'], 500);
            return;
        }

        $this->logger->info('Avatar deleted', ['user_id' => $userId]);

        $this->response->json([
            'success'    => true,
            'message'    => 'تصویر پروفایل حذف شد',
            'avatar_url' => asset('uploads/avatars/default-avatar.png')
        ]);
    }

    public function changePassword(): void
    {
        $userId = user_id();
        
        $currentPassword = $this->request->input('current_password');
        $newPassword = $this->request->input('new_password');
        $confirmPassword = $this->request->input('new_password_confirmation');
        
        if (!$currentPassword || !$newPassword || !$confirmPassword) {
            $this->session->setFlash('error', 'لطفاً تمام فیلدها را پر کنید');
            redirect('profile');
        }
        
        if (strlen($newPassword) < 8) {
            $this->session->setFlash('error', 'رمز عبور جدید باید حداقل 8 کاراکتر باشد');
            redirect('profile');
        }
        
        if ($newPassword !== $confirmPassword) {
            $this->session->setFlash('error', 'رمز عبور جدید و تکرار آن یکسان نیستند');
            redirect('profile');
        }
        
        $user = $this->userService->findById($userId);
        if (!verify_password($currentPassword, $user->password)) {
            $this->session->setFlash('error', 'رمز عبور فعلی اشتباه است');
            redirect('profile');
        }
        
        $result = $this->userService->update($userId, [
            'password' => hash_password($newPassword),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        if ($result) {
            // ✅ اصلاح logger
            $this->logger->info('Password changed', ['user_id' => $userId]);
            
            $this->session->setFlash('success', 'رمز عبور با موفقیت تغییر یافت');
        } else {
            $this->session->setFlash('error', 'خطا در تغییر رمز عبور');
        }
        
        redirect('profile');
    }
}