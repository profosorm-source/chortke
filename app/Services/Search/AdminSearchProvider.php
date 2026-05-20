<?php

declare(strict_types=1);

namespace App\Services\Search;


/**
 * 🚀 UPG-01: AdminSearchProvider - تأمین‌کننده اختصاصی جستجوی ادمین و پنل مدیریت
 */
class AdminSearchProvider extends BaseSearchProvider
{
    public function __construct(
        \App\Models\AdvancedSearch $searchModel,
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        private AdminSearchGateway $gateway
    ) {
        parent::__construct($searchModel, $cache, $logger);
    }

    /**
     * جستجوی سراسری ادمین در کل جداول سیستم
     */
    public function searchAdmin(string $query, int $limit = 5): array
    {
        $this->logSearch('admin', $query, null);

        $cacheKey = "global_search_admin:" . md5($query . ':' . $limit);
        $tags = $this->searchTags('search:admin');
        $cached = $this->cacheGet($cacheKey, $tags);
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
            'tickets' => $this->searchTicketsGlobal($q, $limit),
            'withdrawals' => $this->searchWithdrawals($q, $limit),
            'deposits' => $this->searchDeposits($q, $limit),
            'ads' => $this->searchAds($q, $limit),
        ];

        $total = array_sum(array_map('count', $results));
        $results['total'] = $total;

        $this->cacheSetSeconds($cacheKey, $results, self::CACHE_TTL_SECONDS, $tags);

        return $results;
    }

    public function searchBanners(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('banners', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('banners', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:banners');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchBanners($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }

    public function searchContent(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('content', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('content', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:content');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchContent($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }

    public function searchTokens(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('tokens', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('tokens', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:tokens');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchTokens($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }

    public function searchEmails(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('emails', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('emails', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:emails');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchEmails($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }

    public function searchAdTasks(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('ad_tasks', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('ad_tasks', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:ad_tasks');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchAdTasks($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }

    public function searchInvestments(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('investments', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('investments', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:investments');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchInvestments($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }

    public function searchTickets(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('tickets', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('tickets', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:tickets');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchTicketsAdmin($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }

    public function searchInfluencers(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('influencers', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('influencers', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:influencers');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchInfluencersAdmin($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }


    public function searchRegisteredModule(string $module, string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('admin_registered:' . $module, $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('registered:' . $module, array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:' . $module);
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $result = $this->gateway->searchRegistered($module, $this->sanitize($q), $filters, $limit, $offset);
        $this->cacheSetSeconds($cacheKey, $result, self::CACHE_TTL_SECONDS, $tags);
        return $result;
    }

    public function registeredModules(): array
    {
        return $this->gateway->registeredModules();
    }

    // Internal delegators

    private function searchUsers(string $q, int $limit): array
    {
        return $this->gateway->quickSearchUsers($q, $limit);
    }

    private function searchTransactions(string $q, int $limit): array
    {
        return $this->gateway->quickSearchTransactions($q, null, $limit);
    }

    private function searchTicketsGlobal(string $q, int $limit): array
    {
        return $this->gateway->quickSearchTickets($q, null, $limit);
    }

    private function searchWithdrawals(string $q, int $limit): array
    {
        return $this->gateway->quickSearchWithdrawals($q, $limit);
    }

    private function searchDeposits(string $q, int $limit): array
    {
        return $this->gateway->quickSearchDeposits($q, $limit);
    }

    private function searchAds(string $q, int $limit): array
    {
        return $this->gateway->quickSearchAds($q, null, $limit);
    }
}
