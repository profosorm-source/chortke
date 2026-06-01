<?php

declare(strict_types=1);

namespace App\Services\Search;


/**
 * 🚀 UPG-01: AdminSearchProvider - تأمین‌کننده اختصاصی جستجوی ادمین و پنل مدیریت
 */
class AdminSearchProvider extends BaseSearchProvider implements \App\Contracts\SearchProviderInterface
{
    private AdminSearchGateway $gateway;
    public function __construct(
        \App\Contracts\CacheInterface $cache,
        \App\Contracts\LoggerInterface $logger,
        AdminSearchGateway $gateway,
        ?\App\Services\Settings\AppSettings $appSettings = null
    ) {        $this->gateway = $gateway;

        parent::__construct($context, $cache, $logger, $settingService);
    }

    public function supports(string $scope): bool
    {
        return $scope === 'admin' || str_starts_with($scope, 'admin_module:');
    }

    public function search(\App\Services\Search\SearchQuery $query): \App\Services\Search\SearchResult
    {
        $scope = $query->getFilters()['scope'] ?? 'admin';
        
        if (str_starts_with($scope, 'admin_module:')) {
            $module = str_replace('admin_module:', '', $scope);
            $result = $this->searchRegisteredModule($module, $query->getTerm() ?? '', $query->getFilters(), $query->getLimit(), $query->getOffset());
        } else {
            $result = $this->searchAdmin($query);
        }

        return new \App\Services\Search\SearchResult(
            $result['items'] ?? $result, // Use items if available, else raw
            $result['total'] ?? (isset($result['items']) ? count($result['items']) : count($result)),
            $result
        );
    }

    /**
     * جستجوی سراسری ادمین در کل جداول سیستم
     */
    public function searchAdmin(SearchQuery $query): array
    {
        $this->logSearch('admin', $query, null);

        $limit = $query->getLimit();
        $offset = $query->getOffset();
        $term = $query->getTerm() ?? '';

        $cacheKey = "global_search_admin:" . md5($term . ':' . $limit . ':' . $offset);
        $tags = $this->searchTags('search:admin');
        
        // Admin search must be real-time to avoid data staleness.
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($term);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return $this->emptyGlobalResult();
        }

        $qObj = clone $query; // Clone to avoid modifying original reference

        $results = [
            'users' => $this->searchUsers($q, $limit),
            'transactions' => $this->searchTransactions($q, $limit),
            'tickets' => $this->searchTicketsGlobal($q, $limit),
            'withdrawals' => $this->searchWithdrawals($q, $limit),
            'deposits' => $this->searchDeposits($q, $limit),
            'ads' => $this->searchAds($q, $limit),
            'kyc' => $this->gateway->searchRegistered('kyc', $qObj)->toArray()['items'] ?? [],
            'bank_cards' => $this->gateway->searchRegistered('bank_cards', $qObj)->toArray()['items'] ?? [],
            'contents' => $this->gateway->searchContent($q, [], $limit, 0)['items'] ?? [],
            'influencers' => $this->gateway->searchInfluencersAdmin($q, [], $limit, 0)['items'] ?? [],
            'investments' => $this->gateway->searchInvestments($q, [], $limit, 0)['items'] ?? [],
            'bug_reports' => $this->gateway->searchRegistered('bug_report', $qObj)->toArray()['items'] ?? [],
            'escrows' => $this->gateway->searchRegistered('escrow', $qObj)->toArray()['items'] ?? [],
        ];

        $total = 0;
        foreach ($results as $domain => $items) {
            $count = is_array($items) ? count($items) : (isset($items['items']) ? count($items['items']) : 0);
            $total += $count;
        }
        $results['total'] = $total;

        // Force Admin Search cache TTL to 0 or very short (1 second) to prevent staleness
        $this->cacheSetSeconds($cacheKey, $results, 1, $tags);

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

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('banners'), $tags);
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

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('seo'), $tags);
        return $result;
    }

    public function searchContentForExport(string $q, array $filters = [], int $limit = 1000, int $offset = 0): array
    {
        $this->logSearch('content_export', $q, null);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('content_export', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:content', 'export:content');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->gateway->searchContentExport($q, $filters, $limit, $offset);

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('seo'), $tags);
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

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('settings'), $tags);
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

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('transactions'), $tags);
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

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('ads'), $tags);
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

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('lottery'), $tags);
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

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('tickets'), $tags);
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

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('influencers'), $tags);
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

        $searchResult = $this->gateway->searchRegistered($module, $this->sanitize($q), $filters, $limit, $offset);
        $result = $searchResult instanceof \App\Services\Search\SearchResult ? $searchResult->toArray() : (array)$searchResult;
        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL($module), $tags);
        return $result;
    }

    public function registeredModules(): array
    {
        return $this->gateway->registeredModules();
    }

    // Internal delegators

    private function searchUsers(string $q, int $limit): array
    {
        return $this->gateway->quickSearchUsers(new SearchQuery($q, [], $limit, 0))->toArray()['items'] ?? [];
    }

    private function searchTransactions(string $q, int $limit): array
    {
        return $this->gateway->quickSearchTransactions(new SearchQuery($q, [], $limit, 0))->toArray()['items'] ?? [];
    }

    private function searchTicketsGlobal(string $q, int $limit): array
    {
        return $this->gateway->quickSearchTickets(new SearchQuery($q, [], $limit, 0))->toArray()['items'] ?? [];
    }

    private function searchWithdrawals(string $q, int $limit): array
    {
        return $this->gateway->quickSearchWithdrawals(new SearchQuery($q, [], $limit, 0))->toArray()['items'] ?? [];
    }

    private function searchDeposits(string $q, int $limit): array
    {
        return $this->gateway->quickSearchDeposits(new SearchQuery($q, [], $limit, 0)); // Already returns array of items
    }

    private function searchAds(string $q, int $limit): array
    {
        return $this->gateway->quickSearchAds(new SearchQuery($q, [], $limit, 0))->toArray()['items'] ?? [];
    }

    public function quickSearchAds(string $q, ?int $userId, int $limit): array
    {
        $filters = $userId ? ['user_id' => $userId] : [];
        return $this->gateway->quickSearchAds(new SearchQuery($q, $filters, $limit, 0))->toArray();
    }

    public function quickSearchSubmissions(string $q, ?int $userId, int $limit): array
    {
        return $this->gateway->quickSearchSubmissions($q, $userId, $limit);
    }
}
