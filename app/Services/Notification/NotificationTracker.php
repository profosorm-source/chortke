<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Core\Cache;
use App\Contracts\LoggerInterface;

class NotificationTracker extends \App\Services\BaseService
{
    private const UNREAD_CACHE_PREFIX = 'notif_unread:';
    private const UNREAD_CACHE_TTL = 5;

    public function __construct(
        private Notification $notificationModel,
        protected ?Cache $cache,
        protected LoggerInterface $logger,
        private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null
    ) {
        parent::__construct($logger);
    }

    public function getLatestForUser(int $userId, int $limit = 10): array
    {
        return $this->notificationModel->getLatestForUser($userId, $limit);
    }

    public function getUserNotifications(int $userId, bool $onlyUnread = false, int $limit = 20, int $offset = 0): array
    {
        return $this->notificationModel->getUserNotifications($userId, $onlyUnread, $limit, $offset);
    }

    public function countUserNotifications(int $userId, bool $onlyUnread = false): int
    {
        return $this->notificationModel->countUserNotifications($userId, $onlyUnread);
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        $result = $this->notificationModel->markAsRead($notificationId, $userId);
        if ($result) {
            $this->invalidateUnreadCache($userId);
        }
        return $result;
    }

    public function markAllAsRead(int $userId): bool
    {
        $result = $this->notificationModel->markAllAsRead($userId);
        if ($result) {
            $this->invalidateUnreadCache($userId);
        }
        return $result;
    }

    public function archive(int $notificationId, int $userId): bool
    {
        $result = $this->notificationModel->archive($notificationId, $userId);
        if ($result) {
            $this->invalidateUnreadCache($userId);
        }
        return $result;
    }

    public function softDelete(int $notificationId, int $userId): bool
    {
        $result = $this->notificationModel->softDelete($notificationId, $userId);
        if ($result) {
            $this->invalidateUnreadCache($userId);
        }
        return $result;
    }

    public function getUnreadCount(int $userId): int
    {
        $cacheKey = self::UNREAD_CACHE_PREFIX . $userId;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (int)$cached;
        }

        $count = $this->notificationModel->countUnread($userId);
        $this->cache->put($cacheKey, $count, self::UNREAD_CACHE_TTL);

        return $count;
    }

    public function invalidateUnreadCache(int $userId): void
    {
        if ($this->cacheInvalidation) {
            $this->cacheInvalidation->invalidateUser($userId);
        } else {
            $this->cache->forget(self::UNREAD_CACHE_PREFIX . $userId);
        }
    }

    public function getNewNotificationsAfterId(int $userId, int $lastId, int $limit = 20): array
    {
        return $this->notificationModel->getNewNotificationsAfterId($userId, $lastId, $limit);
    }
}
