<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use App\Contracts\SearchServiceInterface;

/**
 * 🚀 UPG-01: SearchOrchestrator - هماهنگ‌کننده نهایی که درخواست‌ها را به تأمین‌کنندگان مربوطه هدایت می‌کند
 */
class SearchOrchestrator extends \App\Services\BaseService implements SearchServiceInterface
{
    public function __construct(
        private AdminSearchProvider $adminProvider,
        private UserSearchProvider $userProvider,
        private ModuleSearchProvider $moduleProvider,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * هدایت به جستجوی ادمین
     */
    public function searchAdmin(string $query, int $limit = 5): array
    {
        return $this->adminProvider->searchAdmin($query, $limit);
    }

    /**
     * هدایت به جستجوی کاربر
     */
    public function searchUser(string $query, int $userId, int $limit = 5): array
    {
        return $this->userProvider->searchUser($query, $userId, $limit);
    }

    /**
     * هدایت به جستجوی ماژول‌ها
     */
    public function searchModules($modules, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->moduleProvider->searchModules($modules, $filters, $limit, $offset);
    }

    public function invalidateModuleCache(string $module): void
    {
        $this->moduleProvider->invalidateModuleCache($module);
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
