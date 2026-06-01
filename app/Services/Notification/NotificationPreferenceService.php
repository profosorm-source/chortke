<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\NotificationPreference;
use App\Contracts\LoggerInterface;
use Core\Cache;
use App\Services\Cache\CacheInvalidationService;

class NotificationPreferenceService
{
    protected array $localCache = [];

    private NotificationPreference $prefModel;
    private ?CacheInvalidationService $cacheInvalidation;
    public function __construct(
        NotificationPreference $prefModel,
        ?CacheInvalidationService $cacheInvalidation = null
    ) {        $this->prefModel = $prefModel;
        $this->cacheInvalidation = $cacheInvalidation;

        
    }

    /**
     * پیش‌بارگذاری تنظیمات برای گروهی از کاربران (🚀 BUG-10 Fix)
     */
    public function prefetchPreferences(array $userIds): void
    {
        if (empty($userIds)) return;
        
        $prefs = $this->prefModel->getByUsers($userIds);

        foreach ($prefs as $pref) {
            $this->localCache[$pref->user_id] = $pref;
            $this->cacheService->put("user_prefs:{$pref->user_id}", json_encode($pref), 300);
        }
    }

    public function getPreferences(int $userId): object
    {
        if (isset($this->localCache[$userId])) {
            return $this->localCache[$userId];
        }

        try {
            $cached = $this->cacheService->get("user_prefs:{$userId}");
            if ($cached) {
                $decoded = json_decode($cached);
                if ($decoded) {
                    $this->localCache[$userId] = $decoded;
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
        }

        $pref = $this->prefModel->getOrCreate($userId);
        $this->localCache[$userId] = $pref;

        try {
            $this->cacheService->put("user_prefs:{$userId}", json_encode($pref), 300);
        } catch (\Throwable $e) {
        }

        return $pref;
    }

    public function updatePreferences(int $userId, array $data): bool
    {
        $allowedFields = $this->prefModel->getAllowedFields();
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        unset($this->localCache[$userId]);
        try {
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateUser($userId);
            } else {
                $this->cacheService->forget("user_prefs:{$userId}");
            }
        } catch (\Throwable $e) {
        }

        return $this->prefModel->updateForUser($userId, $updateData);
    }

    public function isInAppEnabled(int $userId, string $type): bool
    {
        $pref = $this->getPreferences($userId);
        $field = "{$type}_enabled";
        return (bool) ($pref->$field ?? $pref->in_app_notifications ?? true);
    }

    public function isPushEnabled(int $userId, string $type): bool
    {
        $pref = $this->getPreferences($userId);
        return (bool) ($pref->push_notifications ?? true);
    }

    public function isSmsEnabled(int $userId, string $type): bool
    {
        $pref = $this->getPreferences($userId);
        return (bool) ($pref->sms_notifications ?? false); // SMS is opt-in or based on settings
    }

    public function isEmailEnabled(int $userId, string $type): bool
    {
        $pref = $this->getPreferences($userId);
        return (bool) ($pref->email_notifications ?? false);
    }

    public function isInDndMode(int $userId): bool
    {
        $pref = $this->getPreferences($userId);
        // منطق DND ساده شده با فرض وجود فیلدها در آبجکت کش شده
        if (!empty($pref->dnd_start) && !empty($pref->dnd_end)) {
            $now = date('H:i:s');
            if ($pref->dnd_start < $pref->dnd_end) {
                return $now >= $pref->dnd_start && $now <= $pref->dnd_end;
            }
            return $now >= $pref->dnd_start || $now <= $pref->dnd_end;
        }
        return false;
    }

    public function getNextDndEndTime(int $userId): ?string
    {
        // این متد در مدل یا کلاس والد پیاده‌سازی شده
        if (method_exists($this->prefModel, 'getNextDndEndTime')) {
            return $this->prefModel->getNextDndEndTime($userId);
        }
        // Fallback calculated logic
        return date('Y-m-d H:i:s', strtotime('+1 hour'));
    }
}
