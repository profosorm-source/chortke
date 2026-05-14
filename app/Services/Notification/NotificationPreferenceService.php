<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\NotificationPreference;
use App\Contracts\LoggerInterface;

class NotificationPreferenceService extends \App\Services\BaseService
{
    public function __construct(
        private NotificationPreference $prefModel,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function getPreferences(int $userId): object
    {
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

        return $this->prefModel->updateForUser($userId, $updateData);
    }

    public function isInAppEnabled(int $userId, string $type): bool
    {
        return $this->prefModel->isInAppEnabled($userId, $type);
    }

    public function isPushEnabled(int $userId, string $type): bool
    {
        return $this->prefModel->isPushEnabled($userId, $type);
    }

    public function isInDndMode(int $userId): bool
    {
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
