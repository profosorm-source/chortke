<?php
// app/Services/SocialAccountService.php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\User;
use Core\Session;

use App\Contracts\LoggerInterface;
class SocialAccountService
{
    private \App\Models\Notification $notificationModel;
    private $socialAccountModel;
    private $userModel;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \App\Models\SocialAccount $socialAccountModel,
        \App\Models\User $userModel,
        \App\Models\Notification $notificationModel
    ) {        $this->logger = $logger;

                $this->socialAccountModel = $socialAccountModel;
        $this->userModel = $userModel;
        $this->notificationModel = $notificationModel;
    }

    /**
     * ثبت حساب اجتماعی جدید
     */
    public function register(int $userId, array $data): array
    {
        // بررسی وجود کاربر
        $user = $this->userModel->find($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد.'];
        }

        // بررسی تکراری نبودن
        if ($this->socialAccountModel->existsByPlatformAndUsername($data['platform'], $data['username'])) {
            return ['success' => false, 'message' => 'این نام کاربری قبلاً در این پلتفرم ثبت شده است.'];
        }

        // بررسی تعداد حساب‌های کاربر در هر پلتفرم (حداکثر 1)
        $existing = $this->socialAccountModel->findByUserAndPlatform($userId, $data['platform']);
        if ($existing) {
            return ['success' => false, 'message' => 'شما قبلاً یک حساب در این پلتفرم ثبت کرده‌اید.'];
        }

        // بررسی حداقل‌ها
        $validation = $this->validateAccountQuality($data);
        if (!$validation['passed']) {
            return ['success' => false, 'message' => $validation['message']];
        }

        // ایجاد
        $account = $this->socialAccountModel->create([
            'user_id'             => $userId,
            'platform'            => $data['platform'],
            'username'            => $data['username'],
            'profile_url'         => $data['profile_url'],
            'follower_count'      => (int) ($data['follower_count'] ?? 0),
            'following_count'     => (int) ($data['following_count'] ?? 0),
            'post_count'          => (int) ($data['post_count'] ?? 0),
            'engagement_rate'     => (float) ($data['engagement_rate'] ?? 0),
            'account_age_months'  => (int) ($data['account_age_months'] ?? 0),
        ]);

        if (!$account) {
            return ['success' => false, 'message' => 'خطا در ثبت حساب. لطفاً دوباره تلاش کنید.'];
        }

        // لاگ
$this->logger->info('social.account.registered', [
    'channel' => 'social_account',
    'user_id' => $userId,
    'platform' => $data['platform'] ?? null,
    'username' => $data['username'] ?? null,
]);
        return [
            'success' => true,
            'message' => 'حساب شما با موفقیت ثبت شد و در انتظار بررسی قرار گرفت.',
            'account' => $account,
        ];
    }

    /**
     * بررسی کیفیت حساب (ضد فیک)
     */
    private function validateAccountQuality(array $data): array
    {
        $platform = $data['platform'];
        $followerCount = (int) ($data['follower_count'] ?? 0);
        $postCount = (int) ($data['post_count'] ?? 0);
        $accountAge = (int) ($data['account_age_months'] ?? 0);

        // حداقل سن حساب: 3 ماه
        if ($accountAge < 3) {
            return [
                'passed'  => false,
                'message' => 'حساب شما باید حداقل ۳ ماه قدمت داشته باشد.',
            ];
        }

        // حداقل تعداد پست
        $minPosts = $this->getMinPosts($platform);
        if ($postCount < $minPosts) {
            return [
                'passed'  => false,
                'message' => "حساب شما باید حداقل {$minPosts} پست/ویدیو داشته باشد.",
            ];
        }

        // حداقل فالوور
        $minFollowers = $this->getMinFollowers($platform);
        if ($followerCount < $minFollowers) {
            return [
                'passed'  => false,
                'message' => "حساب شما باید حداقل {$minFollowers} فالوور/دنبال‌کننده داشته باشد.",
            ];
        }

        // نسبت فالوور به فالووینگ (تشخیص فیک)
        $followingCount = (int) ($data['following_count'] ?? 0);
        if ($followingCount > 0 && $followerCount > 0) {
            $ratio = $followingCount / $followerCount;
            // اگر فالووینگ بیش از 5 برابر فالوور باشد → مشکوک
            if ($ratio > 5) {
                return [
                    'passed'  => false,
                    'message' => 'نسبت فالوور به فالووینگ حساب شما غیرطبیعی است.',
                ];
            }
        }

        return ['passed' => true, 'message' => ''];
    }

    /**
     * حداقل پست بر اساس پلتفرم
     */
    private function getMinPosts(string $platform): int
    {
        $defaults = [
            'instagram' => 10,
            'youtube'   => 5,
            'telegram'  => 0,
            'tiktok'    => 5,
            'twitter'   => 10,
        ];
        return $defaults[$platform] ?? 10;
    }

    /**
     * حداقل فالوور بر اساس پلتفرم
     */
    private function getMinFollowers(string $platform): int
    {
        $defaults = [
            'instagram' => 50,
            'youtube'   => 20,
            'telegram'  => 0,
            'tiktok'    => 30,
            'twitter'   => 20,
        ];
        return $defaults[$platform] ?? 50;
    }

    /**
     * تایید حساب توسط ادمین
     */
    public function verify(int $accountId, int $adminId): array
    {
        $account = $this->socialAccountModel->find($accountId);
        if (!$account) {
            return ['success' => false, 'message' => 'حساب یافت نشد.'];
        }

        if ($account->status === 'verified') {
            return ['success' => false, 'message' => 'این حساب قبلاً تایید شده است.'];
        }

        $result = $this->socialAccountModel->update($accountId, [
            'status'      => 'verified',
            'verified_by' => $adminId,
            'verified_at' => \date('Y-m-d H:i:s'),
        ]);

        if (!$result) {
            return ['success' => false, 'message' => 'خطا در تایید حساب.'];
        }

        $this->logger->info("Admin {$adminId} verified social account #{$accountId}", ['channel' => 'social_account']);

        // نوتیفیکیشن به کاربر
        $this->notifyUser($account->user_id, 'حساب اجتماعی شما تایید شد', 
            "حساب {$account->username} در " . $this->socialAccountModel->platformLabel($account->platform) . " تایید شد. اکنون می‌توانید تسک‌ها را انجام دهید.",
            'success'
        );

        return ['success' => true, 'message' => 'حساب با موفقیت تایید شد.'];
    }

    /**
     * رد حساب توسط ادمین
     */
    public function reject(int $accountId, int $adminId, string $reason): array
    {
        $account = $this->socialAccountModel->find($accountId);
        if (!$account) {
            return ['success' => false, 'message' => 'حساب یافت نشد.'];
        }

        // ثبت تاریخچه رد
        $history = [];
        if ($account->rejection_history) {
            $history = \json_decode($account->rejection_history, true) ?: [];
        }
        $history[] = [
            'reason'     => $reason,
            'admin_id'   => $adminId,
            'date'       => \date('Y-m-d H:i:s'),
        ];

        $result = $this->socialAccountModel->update($accountId, [
            'status'            => 'rejected',
            'rejection_reason'  => $reason,
            'rejection_history' => \json_encode($history, \JSON_UNESCAPED_UNICODE),
        ]);

        if (!$result) {
            return ['success' => false, 'message' => 'خطا در رد حساب.'];
        }

        $this->logger->info("Admin {$adminId} rejected social account #{$accountId}: {$reason}", ['channel' => 'social_account']);

        // نوتیفیکیشن به کاربر
        $this->notifyUser($account->user_id, 'حساب اجتماعی شما رد شد',
            "حساب {$account->username} رد شد. دلیل: {$reason}",
            'danger'
        );

        return ['success' => true, 'message' => 'حساب با موفقیت رد شد.'];
    }

    /**
     * ویرایش حساب توسط کاربر (فقط اگر رد شده یا در انتظار)
     */
    public function updateByUser(int $accountId, int $userId, array $data): array
    {
        $account = $this->socialAccountModel->find($accountId);
        if (!$account || $account->user_id !== $userId) {
            return ['success' => false, 'message' => 'حساب یافت نشد.'];
        }

        if ($account->status === 'verified') {
            return ['success' => false, 'message' => 'حساب تایید‌شده قابل ویرایش نیست.'];
        }

        // بررسی تکراری
        if (!empty($data['username']) && $data['username'] !== $account->username) {
            if ($this->socialAccountModel->existsByPlatformAndUsername($account->platform, $data['username'], $accountId)) {
                return ['success' => false, 'message' => 'این نام کاربری قبلاً ثبت شده است.'];
            }
        }

        $updateData = [
            'username'           => $data['username'] ?? $account->username,
            'profile_url'        => $data['profile_url'] ?? $account->profile_url,
            'follower_count'     => (int) ($data['follower_count'] ?? $account->follower_count),
            'following_count'    => (int) ($data['following_count'] ?? $account->following_count),
            'post_count'         => (int) ($data['post_count'] ?? $account->post_count),
            'account_age_months' => (int) ($data['account_age_months'] ?? $account->account_age_months),
            'status'             => 'pending', // بازگشت به انتظار
        ];

        $result = $this->socialAccountModel->update($accountId, $updateData);

        if (!$result) {
            return ['success' => false, 'message' => 'خطا در بروزرسانی.'];
        }

        return ['success' => true, 'message' => 'اطلاعات حساب بروزرسانی شد و مجدداً برای بررسی ارسال گردید.'];
    }

    /**
     * حذف حساب (Soft Delete)
     */
    public function delete(int $accountId, int $userId): array
    {
        $account = $this->socialAccountModel->find($accountId);
        if (!$account || $account->user_id !== $userId) {
            return ['success' => false, 'message' => 'حساب یافت نشد.'];
        }

        $this->socialAccountModel->softDelete($accountId);

        $this->logger->info("User {$userId} deleted social account #{$accountId}", ['channel' => 'social_account']);

        return ['success' => true, 'message' => 'حساب با موفقیت حذف شد.'];
    }

    /**
     * ارسال نوتیفیکیشن
     */
    private function notifyUser(int $userId, string $title, string $message, string $type = 'info'): void
    {
        try {
            if (\class_exists(\App\Models\Notification::class)) {
                ($this->notificationModel)->create([
                    'user_id' => $userId,
                    'title'   => $title,
                    'message' => $message,
                    'type'    => $type,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->info('notification_error', ['message' => $e->getMessage()]);
        }
    }

    // ─── Query Methods (برای Controllers) ───────────────────────

    public function getByUser(int $userId): array
    {
        return $this->socialAccountModel->getByUser($userId);
    }

    public function find(int $id): ?object
    {
        return $this->socialAccountModel->find($id);
    }

    /**
     * ادمن کے لیے تمام حسابات حاصل کریں (pagination کے ساتھ)
     */
    public function getAllForAdmin(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        return $this->socialAccountModel->getAll($filters, $limit, $offset) ?? [];
    }

    /**
     * ادمن کے لیے حسابات کی تعداد گنتی کریں
     */
    public function countForAdmin(array $filters = []): int
    {
        return $this->socialAccountModel->countAll($filters) ?? 0;
    }

    /**
     * ادمن کے لیے حساب تلاش کریں
     */
    public function findForAdmin(int $id): ?object
    {
        return $this->socialAccountModel->find($id);
    }

    /**
     * ادمن کے لیے حسابات تلاش کریں (filter کے ساتھ)
     */
    public function searchForAdmin(string $search, array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $filters['search'] = $search;
        return $this->getAllForAdmin($filters, $limit, $offset);
    }
}
