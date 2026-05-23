<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Cache;
use App\Models\AdvancedSearch;

/**
 * BaseSearchProvider - کلاس پایه تأمین‌کنندگان جستجو بدون Service Locator
 */
abstract class BaseSearchProvider extends \App\Services\BaseService
{
    protected AdvancedSearch $searchModel;
    protected Cache $cache;
    protected LoggerInterface $logger;

    protected const CACHE_TTL_SECONDS = 300;
    protected const CACHE_TTL_MINUTES = 5;
    protected const DEFAULT_LIMIT = 20;
    protected const MAX_LIMIT = 100;
    protected const MODULES = ['social_task', 'influencer', 'vitrine', 'custom_task', 'investment', 'prediction', 'lottery', 'content', 'coupon', 'ticket', 'seo_ad', 'direct_message'];

    public function __construct(
        AdvancedSearch $searchModel,
        Cache $cache,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->searchModel = $searchModel;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    // ─────────────────────────────────────────────────────────────
    // Shared Helpers
    // ─────────────────────────────────────────────────────────────

    protected function sanitize(string $q): string
    {
        $q = trim(mb_substr($q, 0, 100));
        return trim(preg_replace('/[%_\\]/', '\\$0', $q));
    }

    protected function generateCacheKey(string $module, array $filters, int $limit, int $offset): string
    {
        $filterHash = md5(json_encode($filters));
        return "search:{$module}:{$filterHash}:{$limit}:{$offset}";
    }

    protected function cacheGet(string $key, array $tags = []): mixed
    {
        if (empty($tags)) {
            return $this->cache->get($key);
        }

        try {
            if ($this->hasTagSupport()) {
                return $this->cache->tags($tags)->get($key);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('search.cache.tags_get_failed', [
                'key' => $key,
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->cache->get($key);
    }

    protected function cacheSetSeconds(string $key, mixed $value, int $seconds, array $tags = []): bool
    {
        if (empty($tags)) {
            return $this->cache->setSeconds($key, $value, $seconds);
        }

        try {
            if ($this->hasTagSupport()) {
                return $this->cache->tags($tags)->put($key, $value, max(1, (int) ceil($seconds / 60)));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('search.cache.tags_set_failed', [
                'key' => $key,
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->cache->setSeconds($key, $value, $seconds);
    }

    protected function hasTagSupport(): bool
    {
        return method_exists($this->cache, 'tags') && $this->cache->driver() === 'redis';
    }

    protected function getCacheTTL(string $scope): int
    {
        return match ($scope) {
            'transactions', 'live_transactions', 'direct_messages', 'user_dms', 'user_transactions' => 15,
            'tickets', 'ads', 'banners', 'influencers', 'tasks', 'user_tickets', 'user_ads', 'user_tasks' => 120,
            'system_settings', 'settings', 'lottery', 'prediction', 'seo', 'seo_ad', 'coupons' => 3600,
            default => 300,
        };
    }

    protected function searchTags(string ...$tags): array
    {
        return array_values(array_unique(array_filter(array_merge(['search'], $tags))));
    }

    protected function logSearch(string $type, string $query, ?int $userId): void
    {
        $this->logger->info('search.performed', [
            'type' => $type,
            'query' => $query,
            'user_id' => $userId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    protected function emptyGlobalResult(): array
    {
        return [
            'users' => [], 'transactions' => [], 'tickets' => [],
            'withdrawals' => [], 'deposits' => [], 'ads' => [], 'total' => 0
        ];
    }

    protected function emptyUserResult(): array
    {
        return [
            'transactions' => [], 'tickets' => [], 'ads' => [], 'tasks' => [], 'total' => 0
        ];
    }
}
