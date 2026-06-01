<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * 🚀 UPG-01: UserSearchProvider - تأمین‌کننده اختصاصی جستجوی عمومی سمت کاربران
 */
class UserSearchProvider extends BaseSearchProvider implements \App\Contracts\SearchProviderInterface
{
    private UserSearchGateway $gateway;
    public function __construct(
        \App\Contracts\CacheInterface $cache,
        \App\Contracts\LoggerInterface $logger,
        UserSearchGateway $gateway,
        ?\App\Services\Settings\AppSettings $appSettings = null
    ) {        $this->gateway = $gateway;

        parent::__construct($context, $cache, $logger, $settingService);
    }

    public function supports(string $scope): bool
    {
        return $scope === 'user';
    }

    public function search(\App\Services\Search\SearchQuery $query): \App\Services\Search\SearchResult
    {
        $userId = (int)($query->getFilters()['user_id'] ?? 0);
        $result = $this->searchUser($query->getTerm() ?? '', $userId, $query->getLimit(), $query->getOffset());

        return new \App\Services\Search\SearchResult(
            $result['items'] ?? $result,
            $result['total'] ?? count($result['items'] ?? $result),
            $result
        );
    }

    /**
     * جستجوی سراسری کاربر روی تمام بخش‌های مرتبط با او (Global User Search)
     */
    public function searchUser(SearchQuery $query, int $userId): array
    {
        $this->logSearch('user_global', $query, $userId);

        $cacheKey = "global_search_user:{$userId}:" . md5($query . ':' . $limit . ':' . $offset);
        $tags = $this->searchTags('search:user', "search:user:{$userId}");
        
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return ['total' => 0];
        }

        // لیست تمام ماژول‌هایی که یک کاربر مجاز است جستجو کند (پوشش تمام Missing Domains)
        $domains = [
            'transactions', 'tickets', 'ads', 'tasks', 'vitrines', 'contents', 'direct_messages',
            'withdrawals', 'manual_deposits', 'crypto_deposits', 'referrals', 'kyc', 'bank_cards', 
            'user_levels', 'score_history', 'notifications', 'audit_trail'
        ];

        $results = [];
        $total = 0;

        foreach ($domains as $domain) {
            // مقادیر limit را کوچک در نظر می‌گیریم تا سرچ سراسری سریع باشد
            $domainResult = $this->searchDomain($domain, $q, $userId, [], $limit, $offset);
            if (!empty($domainResult['items'])) {
                $results[$domain] = $domainResult['items'];
                $total += count($domainResult['items']);
            }
        }

        $finalResult = ['items' => $results, 'total' => $total];

        $ttl = $this->getCacheTTL('search');
        $this->cacheSetSeconds($cacheKey, $finalResult, $ttl, $tags);

        return $finalResult;
    }

    /**
     * جستجوی سراسری توسط یک کاربر در یک دامین خاص (مثلا withdrawals, tickets, ...)
     */
    public function searchDomain(string $domain, string $query, int $userId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $this->logSearch("user_{$domain}", $query, $userId);

        $filters['user_id'] = $userId;
        
        $cacheKey = $this->generateCacheKey("user_{$domain}_{$userId}", $filters, $limit, $offset) . ':' . md5($query);
        $tags = $this->searchTags("search:user", "search:user:{$userId}", "search:domain:{$domain}");
        
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);

        // 🚀 استفاده از شیء SearchQuery برای استانداردسازی
        $searchQuery = new SearchQuery($q, $filters, $limit, $offset);
        
        $results = $this->gateway->searchRegistered($domain, $searchQuery)->toArray();

        $ttl = $this->getCacheTTL('search');
        $this->cacheSetSeconds($cacheKey, $results, $ttl, $tags);

        return $results;
    }
}
