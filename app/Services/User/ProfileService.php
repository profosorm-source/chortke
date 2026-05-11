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
    ) {}

    public function getProfile(int $userId): ?object
    {
        return $this->model->find($userId);
    }

    public function updateProfile(int $userId, array $data): bool
    {
        $allowedFields = ['full_name', 'bio', 'avatar', 'website', 'location'];
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
        foreach ($settings as $key => $value) {
            $this->updateSetting($userId, $key, $value);
        }
        return true;
    }

    private function castValue(string $value): mixed
    {
        if ($value === '1' || $value === '0') return $value === '1';
        if (is_numeric($value)) return strpos($value, '.') !== false ? (float)$value : (int)$value;
        return $value;
    }

    private function serializeValue(mixed $value): string
    {
        if (is_bool($value)) return $value ? '1' : '0';
        return (string)$value;
    }
}

