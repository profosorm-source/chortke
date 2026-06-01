<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\GeoIPService;
use Core\Database;

/**
 * UserService
 *
 * مدیریت موجودیت کاربر (ثبت‌نام، وضعیت، هویت).
 */
class UserService
{
    private User $model;
    private ?GeoIPService $geoService;

    private \Core\TransactionWrapper $transactionWrapper;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \App\Contracts\LoggerInterface $logger,
        User $model,
        ?GeoIPService $geoService = null
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->logger = $logger;

                $this->model = $model;
        $this->geoService = $geoService;
    }

    public function register(array $data): array|false
    {
        $this->logger->info('user.registration.attempt', ['email' => $data['email'] ?? 'unknown']);

        // MED-07: Transition to cryptographically secure random_int instead of basic rand
        $data['username'] = $data['username'] ?? explode('@', $data['email'] ?? 'user')[0] . '_' . \random_int(1000, 9999);
        $data['password'] = hash_password($data['password'] ?? bin2hex(random_bytes(8)));
        
        $data['referral_code'] = $this->generateUniqueReferralCode();
        
        // CRITICAL-02 Fix: Store hashed token in DB
        $plainToken = bin2hex(random_bytes(32));
        $data['email_verification_token'] = hash_hmac('sha256', $plainToken, secure_key());
        
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
            return ['id' => (int)$userId, 'plain_token' => $plainToken];
        }

        return false;
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
        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($id, $data) {
                if (isset($data['email'])) {
                    $existing = $this->findByEmail($data['email']);
                    if ($existing && (int)$existing->id !== $id) {
                        return [
                            'success' => false, 
                            'errors' => ['email' => ['این ایمیل قبلاً توسط کاربر دیگری ثبت شده است']]
                        ];
                    }
                }
    
                // ✅ Enforce Role Hierarchy inside service layer (Defense-in-Depth against privilege escalation)
                if (isset($data['role']) || isset($data['status'])) {
                    $actorId = function_exists('user_id') ? user_id() : null;
                    if ($actorId) {
                        $actor = $this->model->findById($actorId);
                        $target = $this->model->findById($id);
                        if ($actor && $target) {
                            $hierarchy = ['user' => 0, 'admin' => 1, 'super_admin' => 2];
                            $actorLevel = $hierarchy[$actor->role ?? 'user'] ?? 0;
                            $targetLevel = $hierarchy[$target->role ?? 'user'] ?? 0;
                            
                            // Non-super_admins cannot edit other admins
                            if ($actorLevel < 2 && $targetLevel >= 1 && $id !== $actorId) {
                                return [
                                    'success' => false,
                                    'message' => 'شما مجاز به ویرایش سایر مدیران نیستید.'
                                ];
                            }
                            
                            // Cannot assign a role higher than the actor's current role
                            if (isset($data['role'])) {
                                $newRoleLevel = $hierarchy[$data['role']] ?? 0;
                                if ($newRoleLevel > $actorLevel) {
                                    return [
                                        'success' => false,
                                        'message' => 'شما نمی‌توانید سطحی بالاتر از سطح خود تخصیص دهید.'
                                    ];
                                }
                            }
                        }
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
                    // ✅ Validate password strength
                    $complexityPattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';
                    if (!preg_match($complexityPattern, (string)$data['password'])) {
                        return [
                            'success' => false,
                            'errors' => ['password' => ['رمز عبور باید حداقل ۸ کاراکتر و شامل حروف بزرگ، کوچک، عدد و نماد باشد']]
                        ];
                    }
                    $updateData['password'] = hash_password((string)$data['password']);
                }
    
                $updateData['updated_at'] = date('Y-m-d H:i:s');
    
                $ok = $this->model->update($id, $updateData);
                
                if ($ok) {
                    return ['success' => true, 'message' => 'کاربر با موفقیت بروزرسانی شد'];
                }
    
                throw new \Exception('خطا در ذخیره مشخصات کاربر');
            });
        } catch (\Exception $e) {
            $this->logger->error('user.update_failed', ['user_id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'بروز خطا در عملیات بروزرسانی کاربر'];
        }
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

    public function getWarningCount(int $userId): int
    {
        $user = $this->findById($userId);
        return $user ? (int)($user->warning_count ?? 0) : 0;
    }

    public function incrementWarningCount(int $userId): bool
    {
        return (bool)$this->model->getDb()->query(
            "UPDATE users SET warning_count = warning_count + 1 WHERE id = ?",
            [$userId]
        );
    }

    public function decrementWarningCount(int $userId): bool
    {
        return (bool)$this->model->getDb()->query(
            "UPDATE users SET warning_count = GREATEST(0, warning_count - 1) WHERE id = ?",
            [$userId]
        );
    }

    public function getFraudScore(int $userId): int
    {
        $user = $this->findById($userId);
        return $user ? (int)($user->fraud_score ?? 0) : 0;
    }

    public function getKycStatus(int $userId): string
    {
        $user = $this->findById($userId);
        return $user ? (string)($user->kyc_status ?? 'unverified') : 'unverified';
    }
}