<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * SearchServiceInterface — قرارداد جامع خدمات جستجوی سیستم
 * 
 * 
 * @method array searchBanners(string $q, array $filters = [], int $limit = 20, int $offset = 0)
 * @method array searchContent(string $q, array $filters = [], int $limit = 20, int $offset = 0)
 * @method array searchContentForExport(string $q, array $filters = [], int $limit = 1000, int $offset = 0)
 * @method array searchTokens(string $q, array $filters = [], int $limit = 20, int $offset = 0)
 * @method array searchEmails(string $q, array $filters = [], int $limit = 20, int $offset = 0)
 * @method array searchAdTasks(string $q, array $filters = [], int $limit = 20, int $offset = 0)
 * @method array searchInvestments(string $q, array $filters = [], int $limit = 20, int $offset = 0)
 * @method array searchTickets(string $q, array $filters = [], int $limit = 20, int $offset = 0)
 * @method array searchInfluencers(string $q, array $filters = [], int $limit = 20, int $offset = 0)
 * @method array quickSearchAds(string $q, ?int $userId, int $limit)
 * @method array quickSearchSubmissions(string $q, ?int $userId, int $limit)
 */
interface SearchServiceInterface
{
    /**
     * روش جدید و اصلی برای جستجو (استفاده از الگوی Strategy و اشیاء Query)
     */
    public function searchQuery(\App\Services\Search\SearchQuery $query): \App\Services\Search\SearchResult;

    /**
     * @deprecated استفاده از searchQuery توصیه می‌شود
     */
    public function searchAdmin(\App\Services\Search\SearchQuery $query): array;
    
    /**
     * @deprecated استفاده از searchQuery توصیه می‌شود
     */
    public function searchUser(\App\Services\Search\SearchQuery $query, int $userId): array;
    
    /**
     * @deprecated استفاده از searchQuery توصیه می‌شود
     */
    public function searchModules($modules, \App\Services\Search\SearchQuery $query): array;
    
    public function invalidateModuleCache(string $module): void;

    /**
     * @deprecated استفاده از searchQuery توصیه می‌شود
     */
    public function searchAdminModule(string $module, \App\Services\Search\SearchQuery $query): array;

    public function registeredAdminModules(): array;
}
