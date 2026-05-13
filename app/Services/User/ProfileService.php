<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use Core\Cache;

use App\Contracts\LoggerInterface;
/**
 * ProfileService
 *
 * مدیریت پروفایل و تنظیمات کاربر.
 */
class ProfileService extends \App\Services\BaseService
{
    private const SETTINGS_CACHE_PREFIX = 'user_settings:';

    public function __construct(
        private User $model,
        protected LoggerInterface $logger,
        private ?Cache $cache = null
    ) {
        parent::__construct($logger);
    }

    public function getProfile(int $userId): ?object
    {
        return $this->model->find($userId);
    }

    public function updateProfile(int $userId, array $data): bool
    {
        $allowedFields = ['full_name', 'bio', 'avatar', 'website', 'location', 'mobile', 'national_id', 'birth_date', 'gender', 'address'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));
        
        if (empty($updateData)) return false;

        $updateData['updated_at'] = date('Y-m-d H:i:s');
        $success = $this->model->update($userId, $updateData);

        if ($success) {
            $this->logger->info('user.profile.updated', ['user_id' => $userId, 'fields' => array_keys($updateData)]);
        }

        return $success;
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
            $this->cache->set($cacheKey, $settings, 3600);
        }

        return $settings;
    }

    public function updateSetting(int $userId, string $key, mixed $value): bool
    {
        $serialized = $this->serializeValue($value);
        $success = $this->model->upsertSetting($userId, $key, $serialized);

        if ($success && $this->cache) {
            $this->cache->delete(self::SETTINGS_CACHE_PREFIX . $userId);
        }

        return $success;
    }

    public function updateMultipleSettings(int $userId, array $settings): bool
    {
        $this->model->beginTransaction();
        try {
            foreach ($settings as $key => $value) {
                $this->updateSetting($userId, $key, $value);
            }
            $this->model->commit();
            return true;
        } catch (\Throwable $e) {
            $this->model->rollback();
            $this->logger->error('user.settings.batch_update_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * اعتبارسنجی بروزرسانی اطلاعات پروفایل
     */
    public function validateProfileUpdate(array $data): array
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
            if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
                $errors['mobile'] = 'شماره موبایل نامعتبر است (باید با 09 شروع شود)';
            }
        }

        // National ID validation
        if (isset($data['national_id']) && $data['national_id'] !== '') {
            $nationalId = trim($data['national_id']);
            if (!preg_match('/^[0-9]{10}$/', $nationalId)) {
                $errors['national_id'] = 'کد ملی باید 10 رقم باشد';
            }
        }

        // Birth date validation
        if (isset($data['birth_date']) && $data['birth_date'] !== '') {
            if (!strtotime($data['birth_date'])) {
                $errors['birth_date'] = 'تاریخ تولد نامعتبر است';
            } else {
                // Check if birth date is in the future
                if (strtotime($data['birth_date']) > time()) {
                    $errors['birth_date'] = 'تاریخ تولد نمی‌تواند در آینده باشد';
                }
                // Check if user is at least 13 years old
                $birthDate = new \DateTime($data['birth_date']);
                $today = new \DateTime();
                $age = $today->diff($birthDate)->y;
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
        // Validate
        $errors = $this->validateProfileUpdate($data);
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
}

