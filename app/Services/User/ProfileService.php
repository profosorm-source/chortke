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
            $this->cache->forget(self::SETTINGS_CACHE_PREFIX . $userId);
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
            if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
                $errors['mobile'] = 'شماره موبایل نامعتبر است (باید با 09 شروع شود)';
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
            if (!preg_match('/^[0-9]{10}$/', $nationalId)) {
                $errors['national_id'] = 'کد ملی باید 10 رقم باشد';
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
        // Validate
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

