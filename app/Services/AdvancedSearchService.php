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
        if (mb_strlen($q, 'UTF-8') < 2) {
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
        if (mb_strlen($q, 'UTF-8') < 2) {
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
            $cached = $this->cache->tags([$module])->get($cacheKey);

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

            // CACHE_TTL is 300 seconds (5 minutes). TaggedCache expects minutes.
            $this->cache->tags([$module])->put($cacheKey, $searchResult, (int)(self::CACHE_TTL / 60));
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
            $this->cache->tags([$module])->flush();
            $this->logger->info("search.cache_invalidated", [
                'module' => $module,
                'driver' => $this->cache->driver()
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("search.cache_invalidation_failed", [
                'module' => $module,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * جستجوی بنرها برای صفحات Admin
     */
    public function searchBanners(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('banners', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('banners', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchBanners($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی محتوا برای صفحات Admin
     */
    public function searchContent(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('content', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('content', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchContent($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی API Tokens برای صفحات Admin
     */
    public function searchTokens(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('tokens', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('tokens', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchTokens($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی Email Queue برای صفحات Admin
     */
    public function searchEmails(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('emails', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('emails', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchEmails($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی Bug Reports برای صفحات Admin
     */
    public function searchBugReports(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('bug_reports', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('bug_reports', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchBugReports($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی Ad Tasks (وظایف سفارشی) برای صفحات Admin
     */
    public function searchAdTasks(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('ad_tasks', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('ad_tasks', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchAdTasks($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی سرمایه‌گذاری برای صفحات Admin
     */
    public function searchInvestments(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('investments', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('investments', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchInvestments($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی Audit Trail برای صفحات Admin
     */
    public function searchAuditTrail(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('audit_trail', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('audit_trail', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchAuditTrail($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی تیکت‌ها برای صفحات Admin
     */
    public function searchTickets(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('tickets', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('tickets', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchTicketsAdmin($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * جستجوی اینفلوئنسرها برای صفحات Admin
     */
    public function searchInfluencers(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('influencers', $q, null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('influencers', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($q);
        $result = $this->searchModel->searchInfluencers($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
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
