<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Contracts\LoggerInterface;
use Core\Cache;
use App\Services\BaseService;

/**
 * Centralized cache invalidation rules.
 *
 * این سرویس فقط قوانین invalidation سطح application/domain را نگه می‌دارد؛
 * عملیات low-level همچنان در Core\Cache باقی می‌ماند.
 */
class CacheInvalidationService extends BaseService
{
    public function __construct(
        private Cache $cache,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function forgetMany(array $keys): int
    {
        $deleted = 0;

        foreach (array_unique(array_filter($keys)) as $key) {
            try {
                if ($this->cache->forget((string) $key)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('cache.invalidate_key_failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $deleted;
    }

    public function invalidateUser(int $userId): int
    {
        $deleted = $this->forgetMany([
            "user_dashboard_stats:{$userId}",
            "user_profile:{$userId}",
            "user_permissions:{$userId}",
            "user_notifications_count:{$userId}",
            "user_settings:{$userId}",
            "user_prefs:{$userId}",
            "notif_unread:{$userId}",
            "user_content_stats_{$userId}",
            "user_revenue_{$userId}",
        ]);

        $this->flushTag("search:user:{$userId}");

        return $deleted;
    }

    public function invalidateWallet(int $userId): int
    {
        return $this->forgetMany([
            "wallet_balance:{$userId}",
            "user_dashboard_stats:{$userId}",
            "wallet_transactions_summary:{$userId}",
        ]);
    }

    public function invalidateScore(int $userId, string $domain): int
    {
        $domain = trim($domain);

        return $this->forgetMany([
            "user_score:{$userId}:{$domain}",
            "temp_{$domain}_score:{$userId}",
        ]);
    }

    public function invalidateSearch(?string $scope = null, ?int $userId = null): void
    {
        if ($userId !== null) {
            $this->flushTag("search:user:{$userId}");
            return;
        }

        if ($scope !== null && $scope !== '') {
            $this->flushTag("search:{$scope}");
            return;
        }

        $this->flushTag('search');
    }

    public function invalidateModuleSearch(string $module): void
    {
        $module = trim($module);
        if ($module === '') {
            return;
        }

        $this->flushTag("search:module:{$module}");
        // backward-compatible with old ModuleSearchProvider tags([$module]) keys
        $this->flushTag($module);
    }

    private function flushTag(string $tag): void
    {
        try {
            $this->cache->tags([$tag])->flush();
            $this->logger->info('cache.tag_invalidated', [
                'tag' => $tag,
                'driver' => $this->cache->driver(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.tag_invalidation_failed', [
                'tag' => $tag,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
