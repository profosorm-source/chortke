<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use App\Contracts\SearchServiceInterface;
use Core\RateLimiter;

/**
 * 🚀 UPG-01: SearchOrchestrator - هماهنگ‌کننده نهایی که درخواست‌ها را به تأمین‌کنندگان مربوطه هدایت می‌کند
 */
class SearchOrchestrator extends \App\Services\BaseService implements SearchServiceInterface
{
    public function __construct(
        private AdminSearchProvider $adminProvider,
        private UserSearchProvider $userProvider,
        private ModuleSearchProvider $moduleProvider,
        protected LoggerInterface $logger,
        private ?RateLimiter $rateLimiter = null
    ) {
        parent::__construct($logger);
    }

    private function allowSearch(string $scope, ?int $actorId = null, int $max = 60, int $minutes = 1): bool
    {
        if (!$this->rateLimiter) {
            return true;
        }
        $identity = $actorId !== null ? (string)$actorId : (function_exists('get_client_ip') ? get_client_ip() : 'unknown');
        $key = 'search:' . $scope . ':' . $identity;
        $allowed = $this->rateLimiter->attempt($key, $max, $minutes, false);
        if (!$allowed) {
            $this->logger->warning('search.rate_limited', ['scope' => $scope, 'actor_id' => $actorId, 'key' => $key]);
        }
        return $allowed;
    }

    /**
     * هدایت به جستجوی ادمین
     */
    public function searchAdmin(string $query, int $limit = 5): array
    {
        if (!$this->allowSearch('admin', null, 30, 1)) {
            return ['users' => [], 'transactions' => [], 'tickets' => [], 'withdrawals' => [], 'deposits' => [], 'ads' => [], 'total' => 0, 'rate_limited' => true];
        }
        return $this->adminProvider->searchAdmin($query, $limit);
    }

    /**
     * هدایت به جستجوی کاربر
     */
    public function searchUser(string $query, int $userId, int $limit = 5): array
    {
        if (!$this->allowSearch('user', $userId, 60, 1)) {
            return ['transactions' => [], 'tickets' => [], 'ads' => [], 'tasks' => [], 'total' => 0, 'rate_limited' => true];
        }
        return $this->userProvider->searchUser($query, $userId, $limit);
    }

    /**
     * هدایت به جستجوی ماژول‌ها
     */
    public function searchModules($modules, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $actorId = isset($filters['user_id']) ? (int)$filters['user_id'] : null;
        if (!$this->allowSearch('module', $actorId, 60, 1)) {
            return ['items' => [], 'total' => 0, 'rate_limited' => true];
        }
        return $this->moduleProvider->searchModules($modules, $filters, $limit, $offset);
    }

    public function invalidateModuleCache(string $module): void
    {
        $this->moduleProvider->invalidateModuleCache($module);
    }


    public function searchAdminModule(string $module, string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        if (!$this->allowSearch('admin_module:' . $module, null, 30, 1)) {
            return ['items' => [], 'total' => 0, 'facets' => [], 'rate_limited' => true];
        }
        return $this->adminProvider->searchRegisteredModule($module, $q, $filters, $limit, $offset);
    }

    public function registeredAdminModules(): array
    {
        return $this->adminProvider->registeredModules();
    }

    public function searchQuery(SearchQuery $query): SearchResult
    {
        $result = match ($query->scope) {
            'admin' => $this->searchAdmin($query->q, $query->limit),
            'user' => $query->actorId ? $this->searchUser($query->q, $query->actorId, $query->limit) : [],
            'module' => $this->searchModules($query->filters['modules'] ?? [], $query->filters, $query->limit, $query->offset),
            'admin_module' => $this->searchAdminModule((string)($query->filters['module'] ?? ''), $query->q, $query->filters, $query->limit, $query->offset),
            default => [],
        };

        return SearchResult::fromArray($result);
    }

    // ─────────────────────────────────────────────────────────────
    // Admin Delegations (Proxy calls to Admin Provider)
    // ─────────────────────────────────────────────────────────────

    public function searchBanners(string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->adminProvider->searchBanners($q, $filters, $limit, $offset);
    }

    public function searchContent(string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->adminProvider->searchContent($q, $filters, $limit, $offset);
    }

    public function searchTokens(string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->adminProvider->searchTokens($q, $filters, $limit, $offset);
    }

    public function searchEmails(string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->adminProvider->searchEmails($q, $filters, $limit, $offset);
    }

    public function searchAdTasks(string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->adminProvider->searchAdTasks($q, $filters, $limit, $offset);
    }

    public function searchInvestments(string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->adminProvider->searchInvestments($q, $filters, $limit, $offset);
    }

    public function searchTickets(string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->adminProvider->searchTickets($q, $filters, $limit, $offset);
    }

    public function searchInfluencers(string $q, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->adminProvider->searchInfluencers($q, $filters, $limit, $offset);
    }
}


/** Lightweight DTO for unified search input; kept in this file to avoid adding a new file. */
class SearchQuery
{
    public function __construct(
        public string $q = '',
        public array $filters = [],
        public int $limit = 20,
        public int $offset = 0,
        public array $sort = [],
        public ?int $actorId = null,
        public string $scope = 'admin'
    ) {
        $this->q = trim(mb_substr($this->q, 0, 100));
        $this->limit = max(1, min(100, $this->limit));
        $this->offset = max(0, $this->offset);
        $this->scope = strtolower(trim($this->scope));
    }
}

/** Lightweight DTO for unified search output; kept in this file to avoid adding a new file. */
class SearchResult
{
    public function __construct(
        public array $items = [],
        public int $total = 0,
        public array $facets = [],
        public array $raw = []
    ) {}

    public static function fromArray(array $result): self
    {
        if (array_key_exists('items', $result)) {
            return new self(
                $result['items'] ?? [],
                (int)($result['total'] ?? count($result['items'] ?? [])),
                $result['facets'] ?? [],
                $result
            );
        }

        return new self($result, (int)($result['total'] ?? count($result)), [], $result);
    }

    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => $this->total, 'facets' => $this->facets, 'raw' => $this->raw];
    }
}
