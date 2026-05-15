<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\NotificationPreference;
use App\Contracts\LoggerInterface;

class NotificationPreferenceService extends \App\Services\BaseService
{
    private array $cache = [];

    public function __construct(
        private NotificationPreference $prefModel,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * پیش‌بارگذاری تنظیمات برای گروهی از کاربران (🚀 BUG-10 Fix)
     */
    public function prefetchPreferences(array $userIds): void
    {
        if (empty($userIds)) return;
        
        $prefs = $this->prefModel->getByUsers($userIds);
        foreach ($prefs as $pref) {
            $this->cache[$pref->user_id] = $pref;
        }
    }

    public function getPreferences(int $userId): object
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }
        return $this->prefModel->getOrCreate($userId);
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

        unset($this->cache[$userId]);
        return $this->prefModel->updateForUser($userId, $updateData);
    }

    public function isInAppEnabled(int $userId, string $type): bool
    {
        if (isset($this->cache[$userId])) {
            $pref = $this->cache[$userId];
            $field = "{$type}_enabled";
            return (bool) ($pref->$field ?? $pref->in_app_notifications ?? true);
        }
        return $this->prefModel->isInAppEnabled($userId, $type);
    }

    public function isPushEnabled(int $userId, string $type): bool
    {
        if (isset($this->cache[$userId])) {
            $pref = $this->cache[$userId];
            return (bool) ($pref->push_notifications ?? true);
        }
        return $this->prefModel->isPushEnabled($userId, $type);
    }

    public function isInDndMode(int $userId): bool
    {
        if (isset($this->cache[$userId])) {
            $pref = $this->cache[$userId];
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
        return $this->prefModel->isInDndMode($userId);
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
