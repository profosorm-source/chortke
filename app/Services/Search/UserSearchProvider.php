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
     * جستجوی سراسری توسط یک کاربر (محدود به داده‌های همان کاربر)
     */
    public function searchUser(string $query, int $userId, int $limit = 5, int $offset = 0): array
    {
        $this->logSearch('user', $query, $userId);

        $cacheKey = "global_search_user:{$userId}:" . md5($query . ':' . $limit . ':' . $offset);
        $tags = $this->searchTags('search:user', "search:user:{$userId}");
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return $this->emptyUserResult();
        }

        $results = [
            'transactions'    => $this->searchUserTransactions($q, $userId, $limit),
            'tickets'         => $this->searchUserTickets($q, $userId, $limit),
            'ads'             => $this->searchUserAds($q, $userId, $limit),
            'tasks'           => $this->searchUserTasks($q, $userId, $limit, $offset),
            'vitrines'        => $this->searchUserVitrines($q, $userId, $limit, $offset),
            'contents'        => $this->searchUserContents($q, $userId, $limit, $offset),
            'direct_messages' => $this->searchUserDirectMessages($q, $userId, $limit, $offset),
        ];

        $total = array_sum(array_map('count', $results));
        $results['total'] = $total;

        $this->cacheSetSeconds($cacheKey, $results, self::CACHE_TTL_SECONDS, $tags);

        return $results;
    }

    private function emptyUserResult(): array
    {
        return [
            'transactions'    => [],
            'tickets'         => [],
            'ads'             => [],
            'tasks'           => [],
            'vitrines'        => [],
            'contents'        => [],
            'direct_messages' => [],
            'total'           => 0,
        ];
    }

    // Internal delegators

    private function searchUserTransactions(string $q, int $userId, int $limit): array
    {
        return $this->gateway->searchTransactions($q, $userId, $limit);
    }

    private function searchUserTickets(string $q, int $userId, int $limit): array
    {
        return $this->gateway->searchTickets($q, $userId, $limit);
    }

    private function searchUserAds(string $q, int $userId, int $limit): array
    {
        return $this->gateway->searchAds($q, $userId, $limit);
    }

    private function searchUserTasks(string $q, int $userId, int $limit, int $offset): array
    {
        return $this->gateway->searchTasks($q, $userId, $limit, $offset);
    }

    private function searchUserVitrines(string $q, int $userId, int $limit, int $offset): array
    {
        return $this->gateway->searchVitrines($q, $userId, $limit, $offset);
    }

    private function searchUserContents(string $q, int $userId, int $limit, int $offset): array
    {
        return $this->gateway->searchContents($q, $userId, $limit, $offset);
    }

    private function searchUserDirectMessages(string $q, int $userId, int $limit, int $offset): array
    {
        return $this->gateway->searchDirectMessages($q, $userId, $limit, $offset);
    }
}
