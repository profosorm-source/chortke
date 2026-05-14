<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\GeoIPService;

/**
 * UserService
 *
 * مدیریت موجودیت کاربر (ثبت‌نام، وضعیت، هویت).
 */
class UserService extends \App\Services\BaseService
{
    public function __construct(
        private User $model,
        protected LoggerInterface $logger,
        private ?GeoIPService $geoService = null
    ) {
        parent::__construct($logger);
    }

    public function register(array $data): int|false
    {
        $this->logger->info('user.registration.attempt', ['email' => $data['email'] ?? 'unknown']);

        // MED-07: Transition to cryptographically secure random_int instead of basic rand
        $data['username'] = $data['username'] ?? explode('@', $data['email'] ?? 'user')[0] . '_' . \random_int(1000, 9999);
        $data['password'] = hash_password($data['password'] ?? bin2hex(random_bytes(8)));
        
        $data['referral_code'] = $this->generateUniqueReferralCode();
        $data['email_verification_token'] = bin2hex(random_bytes(32));
        $data['status'] = $data['status'] ?? 'active';
        $data['role'] = $data['role'] ?? 'user';
        $data['created_at'] = date('Y-m-d H:i:s');

        // HIGH-06: DI Architecture Fix — eliminate static Container::make and leverage injected GeoIPService
        try {
            $ipAddress = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null);
            if ($ipAddress && filter_var($ipAddress, FILTER_VALIDATE_IP) && $this->geoService) {
                $location = $this->geoService->lookup($ipAddress);
                if ($location && ($location['source'] ?? '') !== 'default') {
                    $data['country_code'] = strtoupper((string)($location['country_code'] ?? 'IR'));
                    $data['country_name'] = (string)($location['country_name'] ?? 'Iran');
                    
                    if (strlen($data['country_code']) === 2) {
                        $c1 = ord($data['country_code'][0]) + 127397;
                        $c2 = ord($data['country_code'][1]) + 127397;
                        $data['country_flag'] = html_entity_decode("&#$c1;&#$c2;", ENT_HTML5, 'UTF-8');
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('user.registration.geo_detection_failed', ['error' => $e->getMessage()]);
        }

        // Fallbacks
        $data['country_code'] = $data['country_code'] ?? 'IR';
        $data['country_name'] = $data['country_name'] ?? 'Iran';
        $data['country_flag'] = $data['country_flag'] ?? '🇮🇷';

        $userId = $this->model->create($data);

        if ($userId) {
            $this->logger->info('user.registration.success', ['user_id' => $userId]);
        }

        return $userId;
    }

    public function generateUniqueReferralCode(int $maxAttempts = 10): string
    {
        $attempts = 0;
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $attempts++;
            if ($attempts >= $maxAttempts) {
                $this->logger->error('user.referral_code_generation_failed', ['attempts' => $attempts]);
                throw new \RuntimeException('Failed to generate a unique referral code after ' . $maxAttempts . ' attempts.');
            }
        } while ($this->model->findByReferralCode($code));

        return $code;
    }

    public function verifyEmail(int $userId): bool
    {
        return $this->model->verifyEmail($userId);
    }

    public function changePassword(int $userId, string $newPassword): bool
    {
        $success = $this->model->update($userId, [
            'password' => hash_password($newPassword),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($success) {
            $this->logger->info('user.password_changed', ['user_id' => $userId]);
        }

        return $success;
    }

    public function banUser(int $userId, ?string $reason = null): bool
    {
        return $this->model->update($userId, [
            'status' => 'banned',
            'ban_reason' => $reason,
            'banned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function unbanUser(int $userId): bool
    {
        return $this->model->update($userId, [
            'status' => 'active',
            'ban_reason' => null,
            'banned_at' => null,
        ]);
    }

    public function recordLogin(int $userId, ?string $ip = null, ?string $userAgent = null): bool
    {
        $ipAddress = $ip ?? (function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        return $this->model->updateLastLogin(
            $userId,
            $ipAddress,
            $ua
        );
    }

    public function find(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function findById(int $id): ?object
    {
        return $this->find($id);
    }

    public function findByEmail(string $email): ?object
    {
        return $this->model->findByEmail($email);
    }

    public function emailExists(string $email): bool
    {
        return $this->model->findByEmail($email) !== null;
    }

    public function mobileExists(string $mobile): bool
    {
        return $this->model->findByMobile($mobile) !== null;
    }

    public function findByCredentials(string $identifier): ?object
    {
        return $this->model->findByCredentials($identifier);
    }

    public function isBlacklisted(int $userId): bool
    {
        return $this->model->isBlacklisted($userId);
    }

    public function isKycVerified(int $userId): bool
    {
        $user = $this->find($userId);
        return $user && isset($user->kyc_status) && $user->kyc_status === 'verified';
    }

    public function updateUser(int $id, array $data): array
    {
        if (isset($data['email'])) {
            $existing = $this->findByEmail($data['email']);
            if ($existing && (int)$existing->id !== $id) {
                return [
                    'success' => false, 
                    'errors' => ['email' => ['این ایمیل قبلاً توسط کاربر دیگری ثبت شده است']]
                ];
            }
        }

        $updateData = [];
        $updatableFields = ['full_name', 'email', 'role', 'status'];
        
        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($data['password'])) {
            $updateData['password'] = hash_password((string)$data['password']);
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        $ok = $this->model->update($id, $updateData);
        
        if ($ok) {
            return ['success' => true, 'message' => 'کاربر با موفقیت بروزرسانی شد'];
        }

        return ['success' => false, 'message' => 'خطا در ذخیره مشخصات کاربر'];
    }

    public function quickSearch(string $term, int $limit = 5): array
    {
        // M39 Fix: محدود کردن طول عبارت جستجو جهت کاهش فشار پردازشی و فیلتر کاراکترهای کنترلی دیتابیس
        $cleanTerm = \mb_strimwidth(\trim($term), 0, 80, '');
        // حذف کاراکترهای کلیدی وایلدکارد دیتابیس (% و _) جهت پیشگیری از کوئری‌های غیربهینه
        $cleanTerm = \str_replace(['%', '_'], '', $cleanTerm);

        if ($cleanTerm === '') {
            return [];
        }

        // MED-08: Bound search results to safe memory ceilings and verify query components
        $safeLimit = \max(1, \min(50, $limit));

        $query = $this->model->query();
        if (!$query) {
            $this->logger->error('user.quick_search.query_builder_missing');
            return [];
        }

        $query->select('id', 'full_name', 'email', 'mobile', 'kyc_status', 'created_at')
              ->whereNull('deleted_at');

        $this->model->applySearch($query, $cleanTerm);

        return $query->orderBy('created_at', 'DESC')
                     ->limit($safeLimit)
                     ->get() ?? [];
    }

    public function update(int $id, array $data): bool
    {
        return (bool)$this->model->update($id, $data);
    }

    public function searchWithFilters(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->model->searchWithFilters($filters, $limit, $offset);
    }

    public function countWithFilters(array $filters = []): int
    {
        return $this->model->countWithFilters($filters);
    }

    public function getAdminStats(): object
    {
        return cache()->remember('user_admin_stats', 300, function() {
            return $this->model->getAdminStats();
        });
    }
}