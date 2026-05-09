<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdvancedSearch;
use Core\Cache;
use App\Contracts\LoggerInterface;

/**
 * AdvancedSearchService - Unified Advanced Search Service
 *
 * Centralized search for admin, user, and module queries with:
 * - Caching for performance
 * - Analytics logging
 * - QueryBuilder for consistency
 * - Pagination support
 * - SQL injection prevention
 */
class AdvancedSearchService extends \App\Services\BaseService
{
    private AdvancedSearch $searchModel;
    private Cache $cache;
    private const CACHE_TTL = 300; // 5 minutes
    private const MODULES = ['social_task', 'influencer', 'vitrine'];
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(AdvancedSearch $searchModel, LoggerInterface $logger, Cache $cache)
    {
        parent::__construct($logger);
        $this->searchModel = $searchModel;
        $this->cache = $cache;
    }

    /**
     * Global search for admins
     */
    public function searchAdmin(string $query, int $limit = 5): array
    {
        $this->logSearch('admin', $query, null);

        $cacheKey = "global_search_admin:" . md5($query . $limit);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);
        if (strlen($q) < 2) {
            return $this->emptyGlobalResult();
        }

        $results = [
            'users' => $this->searchUsers($q, $limit),
            'transactions' => $this->searchTransactions($q, $limit),
            'tickets' => $this->searchTickets($q, $limit),
            'withdrawals' => $this->searchWithdrawals($q, $limit),
            'deposits' => $this->searchDeposits($q, $limit),
            'ads' => $this->searchAds($q, $limit),
        ];

        $total = array_sum(array_map('count', $results));
        $results['total'] = $total;

        $this->cache->set($cacheKey, $results, self::CACHE_TTL);

        return $results;
    }

    /**
     * Global search for users (limited to their data)
     */
    public function searchUser(string $query, int $userId, int $limit = 5): array
    {
        $this->logSearch('user', $query, $userId);

        $cacheKey = "global_search_user:{$userId}:" . md5($query . $limit);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);
        if (strlen($q) < 2) {
            return $this->emptyUserResult();
        }

        $results = [
            'transactions' => $this->searchUserTransactions($q, $userId, $limit),
            'tickets' => $this->searchUserTickets($q, $userId, $limit),
            'ads' => $this->searchUserAds($q, $userId, $limit),
            'tasks' => $this->searchUserTasks($q, $userId, $limit),
        ];

        $total = array_sum(array_map('count', $results));
        $results['total'] = $total;

        $this->cache->set($cacheKey, $results, self::CACHE_TTL);

        return $results;
    }

    /**
     * Module-specific search.
     */
    public function searchModules(
        $modules,
        array $filters = [],
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0
    ): array {
        $this->logSearch('module', json_encode($filters), null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);
        $modules = is_array($modules) ? $modules : [$modules];

        $results = [];

        foreach ($modules as $module) {
            if (!in_array($module, self::MODULES, true)) {
                continue;
            }

            $cacheKey = $this->generateCacheKey($module, $filters, $limit, $offset);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                $results[$module] = $cached;
                continue;
            }

            $searchResult = match ($module) {
                'social_task' => $this->searchSocialTasks($filters, $limit, $offset),
                'influencer' => $this->searchInfluencers($filters, $limit, $offset),
                'vitrine' => $this->searchVitrine($filters, $limit, $offset),
                default => []
            };

            $this->cache->set($cacheKey, $searchResult, self::CACHE_TTL);
            $results[$module] = $searchResult;
        }

        return $results;
    }

    /**
     * پاک‌سازی امن و غیرمسدودساز کش تگ‌های ماژول‌ها بدون تأثیرگذاری بر پایداری کل دیتابیس ردیس
     */
    public function invalidateModuleCache(string $module): void
    {
        if (!in_array($module, self::MODULES, true)) {
            return;
        }

        try {
            if ($this->cache->driver() === 'redis') {
                $redis = $this->cache->redis();
                if ($redis) {
                    $pattern = "search:{$module}:*";
                    $keys = [];
                    $iterator = null;

                    // استفاده از دستور غیرمسدودساز SCAN بجای KEYS (O(N) safe)
                    while (true) {
                        $result = $redis->scan($iterator, 'MATCH', $pattern, 'COUNT', 100);
                        if ($result === false) {
                            break;
                        }
                        
                        // سازگاری با PhpRedis و پاسخ‌های برگشتی به شکل [new_iterator, keys_array]
                        $currentKeys = [];
                        if (is_array($result)) {
                            if (count($result) === 2 && is_array($result[1])) {
                                $iterator = $result[0];
                                $currentKeys = $result[1];
                            } else {
                                $currentKeys = $result;
                                $iterator = null;
                            }
                        }

                        $keys = array_merge($keys, $currentKeys);
                        if ($iterator === 0 || $iterator === '0' || $iterator === null) {
                            break;
                        }
                    }

                    if (!empty($keys)) {
                        $redis->del($keys);
                        $this->logger->info("search.cache_invalidated", [
                            'module' => $module,
                            'keys_deleted' => count($keys),
                            'driver' => 'redis'
                        ]);
                    }
                }
            } else {
                // حالت درایور فایلی: پاک‌سازی فایل‌های مرتبط با این ماژول انجام پذیرد
                $this->logger->info("search.cache_invalidated", [
                    'module' => $module,
                    'driver' => 'file',
                    'note' => 'در حالت کش فایلی، پاکسازی به صورت خودکار از طریق مکانیزم انقضای فایل‌ها انجام می‌شود.'
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error("search.cache_invalidation_failed", [
                'module' => $module,
                'error' => $e->getMessage()
            ]);
        }
    }

    // Private methods for global search (converted to model delegation)

    private function searchUsers(string $q, int $limit): array
    {
        return $this->searchModel->searchUsers($q, $limit);
    }

    private function searchTransactions(string $q, int $limit): array
    {
        return $this->searchModel->searchTransactions($q, $limit);
    }

    private function searchTickets(string $q, int $limit): array
    {
        return $this->searchModel->searchTickets($q, $limit);
    }

    private function searchWithdrawals(string $q, int $limit): array
    {
        return $this->searchModel->searchWithdrawals($q, $limit);
    }

    private function searchDeposits(string $q, int $limit): array
    {
        return $this->searchModel->searchDeposits($q, $limit);
    }

    private function searchAds(string $q, int $limit): array
    {
        return $this->searchModel->searchAds($q, $limit);
    }

    private function searchUserTransactions(string $q, int $userId, int $limit): array
    {
        return $this->searchModel->searchUserTransactions($q, $userId, $limit);
    }

    private function searchUserTickets(string $q, int $userId, int $limit): array
    {
        return $this->searchModel->searchUserTickets($q, $userId, $limit);
    }

    private function searchUserAds(string $q, int $userId, int $limit): array
    {
        return $this->searchModel->searchUserAds($q, $userId, $limit);
    }

    private function searchUserTasks(string $q, int $userId, int $limit): array
    {
        return $this->searchModel->searchUserTasks($q, $userId, $limit);
    }

    // Module search methods

    private function searchSocialTasks(array $f, int $limit, int $offset): array
    {
        return $this->searchModel->searchSocialTasks($f, $limit, $offset);
    }

    private function searchInfluencers(array $f, int $limit, int $offset): array
    {
        return $this->searchModel->searchInfluencers($f, $limit, $offset);
    }

    private function searchVitrine(array $f, int $limit, int $offset): array
    {
        return $this->searchModel->searchVitrine($f, $limit, $offset);
    }

    // Count methods

    private function countSocialTasks(array $f): int
    {
        return $this->searchModel->countSocialTasks($f);
    }

    private function countInfluencers(array $f): int
    {
        return $this->searchModel->countInfluencers($f);
    }

    private function countVitrine(array $f): int
    {
        return $this->searchModel->countVitrine($f);
    }

    // Helpers

    private function sanitize(string $q): string
    {
        return trim(preg_replace('/[%_\\\\]/', '\\\\$0', $q));
    }

    private function emptyGlobalResult(): array
    {
        return [
            'users' => [], 'transactions' => [], 'tickets' => [],
            'withdrawals' => [], 'deposits' => [], 'ads' => [], 'total' => 0
        ];
    }

    private function emptyUserResult(): array
    {
        return [
            'transactions' => [], 'tickets' => [], 'ads' => [], 'tasks' => [], 'total' => 0
        ];
    }

    private function generateCacheKey(string $module, array $filters, int $limit, int $offset): string
    {
        $filterHash = md5(json_encode($filters));
        return "search:{$module}:{$filterHash}:{$limit}:{$offset}";
    }

    private function logSearch(string $type, string $query, ?int $userId): void
    {
        $this->logger->info('search.performed', [
            'type' => $type,
            'query' => $query,
            'user_id' => $userId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}
