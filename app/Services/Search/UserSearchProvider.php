<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * 🚀 UPG-01: UserSearchProvider - تأمین‌کننده اختصاصی جستجوی عمومی سمت کاربران
 */
class UserSearchProvider extends BaseSearchProvider
{
    public function __construct(
        \App\Models\AdvancedSearch $searchModel,
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        private UserSearchGateway $gateway
    ) {
        parent::__construct($searchModel, $cache, $logger);
    }

    /**
     * جستجوی سراسری کاربر روی تمام بخش‌های مرتبط با او (Global User Search)
     */
    public function searchUser(string $query, int $userId, int $limit = 5, int $offset = 0): array
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

        $results['total'] = $total;

        $ttl = (int) config('search.cache_ttl', 900);
        $this->cacheSetSeconds($cacheKey, $results, $ttl, $tags);

        return $results;
    }

    /**
     * جستجوی سراسری توسط یک کاربر در یک دامین خاص (مثلا withdrawals, tickets, ...)
     * @param string $domain نام دامین (module/table)
     * @param string $query عبارت جستجو
     * @param int $userId شناسه کاربر
     * @param array $filters فیلترهای اضافی
     * @param int $limit تعداد
     * @param int $offset صفحه
     */
    public function searchDomain(string $domain, string $query, int $userId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $this->logSearch("user_{$domain}", $query, $userId);

        $filters['user_id'] = $userId; // 🔒 Force scope to the specific user
        
        $cacheKey = $this->generateCacheKey("user_{$domain}_{$userId}", $filters, $limit, $offset) . ':' . md5($query);
        $tags = $this->searchTags("search:user", "search:user:{$userId}", "search:domain:{$domain}");
        
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);
        
        // Use the centralized Gateway for all read operations, ensuring full-text/index usage, unified pagination, and consistent filtering
        $results = app(\App\Services\Search\AdminSearchGateway::class)->searchRegistered($domain, $q, $filters, $limit, $offset);

        // Standardize TTL to 15 minutes (900 seconds) since we have event-driven invalidation, but keeps a fallback window
        $ttl = (int) config('search.cache_ttl', 900);
        $this->cacheSetSeconds($cacheKey, $results, $ttl, $tags);

        return $results;
    }
}
