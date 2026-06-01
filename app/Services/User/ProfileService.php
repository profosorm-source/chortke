<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use Core\Cache;
use App\Validators\Requests\UpdateProfileRequest;

use App\Contracts\LoggerInterface;
/**
 * ProfileService
 *
 * مدیریت پروفایل و تنظیمات کاربر.
 */
class ProfileService
{
    private const SETTINGS_CACHE_PREFIX = 'user_settings:';

    private \Core\TransactionWrapper $transactionWrapper;
    private \App\Contracts\LoggerInterface $logger;
    private User $model;
    private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \App\Contracts\LoggerInterface $logger,
        User $model,
        ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->logger = $logger;
        $this->model = $model;
        $this->cacheInvalidation = $cacheInvalidation;

        
    }

    public function getProfile(int $userId): ?object
    {
        return $this->model->find($userId);
    }

    private function maskPII(string $field, $value): string
    {
        if (empty($value)) return '';
        $value = (string)$value;
        if ($field === 'national_id' && strlen($value) === 10) {
            return substr($value, 0, 2) . '******' . substr($value, -2);
        }
        if ($field === 'mobile' && strlen($value) === 11) {
            return substr($value, 0, 4) . '***' . substr($value, -2);
        }
        if ($field === 'address') {
            return '[REDACTED]';
        }
        if ($field === 'email') {
            if (strpos($value, '@') !== false) {
                [$u, $domain] = explode('@', $value);
                return substr($u, 0, min(2, strlen($u))) . '***@' . $domain;
            }
            return '[REDACTED]';
        }
        if (in_array($field, ['national_id', 'mobile', 'address', 'email'])) {
            return '[REDACTED]';
        }
        return $value;
    }

    public function updateProfile(int $userId, array $data): bool
    {
        $allowedFields = ['full_name', 'bio', 'avatar', 'website', 'location', 'mobile', 'national_id', 'birth_date', 'gender', 'address'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) return false;

        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($userId, $updateData) {
                // Lock user row (CRIT-01)
                $current = $this->model->getDb()->fetch(
                    "SELECT id, mobile, national_id FROM users WHERE id = ? FOR UPDATE",
                    [$userId]
                );
    
                if (!$current) {
                    return false;
                }
    
                // Check uniqueness under FOR UPDATE lock
                if (isset($updateData['mobile'])) {
                    $exists = $this->model->getDb()->fetch(
                        "SELECT id FROM users WHERE mobile = ? AND id != ? FOR UPDATE",
                        [$updateData['mobile'], $userId]
                    );
                    if ($exists) {
                        throw new \RuntimeException('شماره موبایل قبلاً ثبت شده است');
                    }
                }
                if (isset($updateData['national_id'])) {
                    $exists = $this->model->getDb()->fetch(
                        "SELECT id FROM users WHERE national_id = ? AND id != ? FOR UPDATE",
                        [$updateData['national_id'], $userId]
                    );
                    if ($exists) {
                        throw new \RuntimeException('کد ملی قبلاً ثبت شده است');
                    }
                }
    
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                $success = $this->model->update($userId, $updateData);
                
                if ($success) {
                    if ($this->cacheInvalidation) {
                        $this->cacheInvalidation->invalidateUser($userId);
                    }
    
                    $maskedData = [];
                    foreach ($updateData as $k => $v) {
                        $maskedData[$k] = $this->maskPII($k, $v);
                    }
    
                    $this->logger->info('user.profile.updated', [
                        'user_id' => $userId,
                        'fields' => array_keys($updateData),
                        'values_masked' => $maskedData
                    ]);
                    return true;
                }
    
                return false;
            });
        } catch (\Throwable $e) {
            $this->logger->error('user.profile.update_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function getSettings(int $userId): array
    {
        $cacheKey = self::SETTINGS_CACHE_PREFIX . $userId;
        if ($this->cache && ($cached = $this->cache->get($cacheKey))) {
            return $cached;
        }

        $rawSettings = $this->model->getUserSettings($userId);
        $settings = [];
        foreach ($rawSettings as $row) {
            $settings[$row['setting_key']] = $this->castValue($row['setting_value']);
        }

        if ($this->cache) {
            $this->cache->setSeconds($cacheKey, $settings, 3600);
        }

        return $settings;
    }

    public function updateSetting(int $userId, string $key, mixed $value): bool
    {
        $serialized = $this->serializeValue($value);
        $success = $this->model->upsertSetting($userId, $key, $serialized);

        if ($success) {
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateUser($userId);
            } elseif ($this->cache) {
                $this->cache->forget(self::SETTINGS_CACHE_PREFIX . $userId);
            }
        }

        return $success;
    }

    public function updateMultipleSettings(int $userId, array $settings): bool
    {
        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($userId, $settings) {
                foreach ($settings as $key => $value) {
                    $this->updateSetting($userId, $key, $value);
                }
                return true;
            });
        } catch (\Throwable $e) {
            $this->logger->error('user.settings.batch_update_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * اعتبارسنجی بروزرسانی اطلاعات پروفایل
     */
    public function validateProfileUpdate(array $data, int $userId): array
    {
        $errors = [];

        // Full Name validation
        $fullName = $data['full_name'] ?? '';
        if ($fullName !== '') {
            $fullName = trim($fullName);
            if (mb_strlen($fullName) < 3) {
                $errors['full_name'] = 'نام کامل باید حداقل 3 کاراکتر باشد';
            }
            if (mb_strlen($fullName) > 255) {
                $errors['full_name'] = 'نام کامل بیش از حد طولانی است';
            }
        }

        // Mobile validation
        if (isset($data['mobile']) && $data['mobile'] !== '') {
            $mobile = trim($data['mobile']);

            $validPrefixes = [
                '0910', '0911', '0912', '0913', '0914', '0915', '0916', '0917', '0918', '0919',  // Hamrah-e-aval
                '0901', '0902', '0903',  // Irancell
                '0930', '0933', '0935', '0936', '0937', '0938', '0939',  // Irancell
                '0920', '0921',  // Rightel
                '0932',  // TeleKish
            ];

            $prefix = substr($mobile, 0, 4);
            if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
                $errors['mobile'] = 'شماره موبایل نامعتبر است (باید با 09 شروع شود)';
            } elseif (!in_array($prefix, $validPrefixes)) {
                $errors['mobile'] = 'پیش‌شماره موبایل نامعتبر است';
            } elseif (preg_match('/^09(\d)\1{8}$/', $mobile)) {  // e.g., 09111111111
                $errors['mobile'] = 'شماره موبایل معتبر نیست';
            } else {
                // Check mobile uniqueness
                $existing = $this->model->where('mobile', '=', $mobile)->where('id', '!=', $userId)->first();
                if ($existing) {
                    $errors['mobile'] = 'این شماره موبایل قبلاً ثبت شده است';
                }
            }
        }

        // National ID validation
        if (isset($data['national_id']) && $data['national_id'] !== '') {
            $nationalId = trim($data['national_id']);

            $blacklist = ['0000000000', '1111111111', '2222222222', '3333333333',
                          '4444444444', '5555555555', '6666666666', '7777777777',
                          '8888888888', '9999999999'];

            if (!preg_match('/^[0-9]{10}$/', $nationalId)) {
                $errors['national_id'] = 'کد ملی باید 10 رقم باشد';
            } elseif (in_array($nationalId, $blacklist)) {
                $errors['national_id'] = 'کد ملی نامعتبر';
            } else {
                // Checksum validation
                $check = (int)$nationalId[9];
                $sum = 0;
                for ($i = 0; $i < 9; $i++) {
                    $sum += (int)$nationalId[$i] * (10 - $i);
                }
                $remainder = $sum % 11;
                if (!($remainder < 2 && $check == $remainder) && !($remainder >= 2 && $check == (11 - $remainder))) {
                    $errors['national_id'] = 'کد ملی وارد شده معتبر نیست';
                }
            }
        }

        // Birth date validation
        if (isset($data['birth_date']) && $data['birth_date'] !== '') {
            $date = \DateTime::createFromFormat('Y-m-d', $data['birth_date']);
            if (!$date || $date->format('Y-m-d') !== $data['birth_date']) {
                $errors['birth_date'] = 'تاریخ تولد نامعتبر است (فرمت صحیح: YYYY-MM-DD)';
            } else {
                // Check if birth date is in the future
                if ($date->getTimestamp() > time()) {
                    $errors['birth_date'] = 'تاریخ تولد نمی‌تواند در آینده باشد';
                }
                // Check if user is at least 13 years old
                $today = new \DateTime();
                $age = $today->diff($date)->y;
                if ($age < 13) {
                    $errors['birth_date'] = 'شما باید حداقل 13 سال داشته باشید';
                }
            }
        }

        // Gender validation
        if (isset($data['gender']) && $data['gender'] !== '') {
            if (!in_array($data['gender'], ['male', 'female', 'other'])) {
                $errors['gender'] = 'جنسیت نامعتبر است';
            }
        }

        // Address validation
        if (isset($data['address']) && $data['address'] !== '') {
            $address = trim($data['address']);
            if (mb_strlen($address) > 500) {
                $errors['address'] = 'آدرس بیش از حد طولانی است';
            }
        }

        // Bio validation
        if (isset($data['bio']) && $data['bio'] !== '') {
            $bio = trim($data['bio']);
            if (mb_strlen($bio) > 500) {
                $errors['bio'] = 'بیوگرافی بیش از حد طولانی است';
            }
        }

        return $errors;
    }

    /**
     * بروزرسانی پروفایل به همراه اعتبارسنجی و ذخیره‌سازی
     */
    public function updateProfileWithValidation(int $userId, array $data): array
    {
        $request = new UpdateProfileRequest($data);
        if (!$request->validate()) {
            return ['success' => false, 'errors' => $request->errors()];
        }

        $data = $request->validated();

        // Validate business rules and unique cases
        $errors = $this->validateProfileUpdate($data, $userId);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Sanitize
        $sanitized = [];
        $allowedFields = ['full_name', 'bio', 'avatar', 'website', 'location', 'mobile', 'national_id', 'birth_date', 'gender', 'address'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];
                if (is_string($value)) {
                    $value = trim($value);
                    // Sanitize HTML-prone fields
                    if (in_array($field, ['bio', 'address', 'website', 'full_name'])) {
                        $value = strip_tags($value);
                    }
                }
                $sanitized[$field] = $value;
            }
        }

        // Update
        if ($this->updateProfile($userId, $sanitized)) {
            return ['success' => true, 'message' => 'پروفایل با موفقیت بروزرسانی شد'];
        }

        return ['success' => false, 'errors' => ['general' => 'خطا در بروزرسانی اطلاعات پروفایل']];
    }

    private function castValue(string $value): mixed
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '1' || $normalized === 'true' || $normalized === 'yes' || $normalized === 'on') {
            return true;
        }
        if ($normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
            return false;
        }
        if (is_numeric($value)) return strpos($value, '.') !== false ? (float)$value : (int)$value;
        return $value;
    }

    private function serializeValue(mixed $value): string
    {
        if (is_bool($value)) return $value ? '1' : '0';
        return (string)$value;
    }

    /**
     * واکشی حساب‌های متصل شبکه‌های اجتماعی کاربر
     */
    public function getUserSocialAccounts(int $userId): array
    {
        $sql = "SELECT * FROM user_social_accounts WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC";
        return $this->model->getDb()->query($sql, [$userId])->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * ثبت حساب شبکه اجتماعی جدید برای کاربر
     */
    public function addSocialAccount(int $userId, string $platform, string $username, string $accessToken = ''): array
    {
        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($userId, $platform, $username, $accessToken) {
                $existsSql = "SELECT COUNT(*) FROM user_social_accounts WHERE platform = ? AND username = ? AND deleted_at IS NULL";
                $count = (int)$this->model->getDb()->fetchColumn($existsSql, [$platform, $username]);
                if ($count > 0) {
                    return ['success' => false, 'message' => 'این حساب کاربری قبلاً ثبت شده است'];
                }
    
                $sql = "INSERT INTO user_social_accounts (user_id, platform, username, profile_url, follower_count, following_count, post_count, engagement_rate, account_age_months, status, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, 0, 0, 0, 0.0, 0, 'pending', NOW(), NOW())";
                
                $profileUrl = "https://{$platform}.com/{$username}";
                $this->model->getDb()->query($sql, [$userId, $platform, $username, $profileUrl]);
    
                $accountId = (int)$this->model->getDb()->lastInsertId();
    
                return ['success' => true, 'id' => $accountId, 'message' => 'حساب با موفقیت ثبت شد'];
            });
        } catch (\Throwable $e) {
            $this->logger->error('user.social_account.add_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ثبت حساب شبکه‌های اجتماعی'];
        }
    }
}

