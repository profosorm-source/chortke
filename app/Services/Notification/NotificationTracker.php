<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Core\Cache;
use App\Contracts\LoggerInterface;

class NotificationTracker
{
    private const UNREAD_CACHE_PREFIX = 'notif_unread:';
    private const UNREAD_CACHE_TTL = 5;

    private Notification $notificationModel;
    private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation;
    public function __construct(
        Notification $notificationModel,
        ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null
    ) {        $this->notificationModel = $notificationModel;
        $this->cacheInvalidation = $cacheInvalidation;

        
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
        \Core\Cache::getInstance()->put($cacheKey, $count, self::UNREAD_CACHE_TTL);

        return $count;
    }

    public function invalidateUnreadCache(int $userId): void
    {
        if ($this->cacheInvalidation) {
            $this->cacheInvalidation->invalidateUser($userId);
        } else {
            \Core\Cache::getInstance()->forget(self::UNREAD_CACHE_PREFIX . $userId);
        }
    }

    public function invalidateUnreadCacheBulk(array $userIds): void
    {
        if (empty($userIds)) return;

        if ($this->cacheInvalidation && method_exists($this->cacheInvalidation, 'invalidateUsers')) {
            $this->cacheInvalidation->invalidateUsers($userIds);
            return;
        }

        $cache = \Core\Cache::getInstance();
        $redis = clone $cache->driver() === 'redis' ? clone $cache->redis() : null;

        if ($redis) {
            $redis->multi(\Redis::PIPELINE);
            foreach ($userIds as $uid) {
                $key = method_exists($cache, 'redisKey') ? $cache->redisKey(self::UNREAD_CACHE_PREFIX . $uid) : self::UNREAD_CACHE_PREFIX . $uid;
                $redis->del($key);
            }
            $redis->exec();
        } else {
            foreach ($userIds as $uid) {
                $cache->forget(self::UNREAD_CACHE_PREFIX . $uid);
            }
        }
    }

    public function getNewNotificationsAfterId(int $userId, int $lastId, int $limit = 20): array
    {
        return $this->notificationModel->getNewNotificationsAfterId($userId, $lastId, $limit);
    }
}
