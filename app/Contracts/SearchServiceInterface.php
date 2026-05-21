<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * SearchServiceInterface — قرارداد جامع خدمات جستجوی سیستم
 * 
 * L-SRV-03 Fix: تعریف اینترفیس رسمی برای سرویس ارکستریتور جستجو جهت تسهیل تمسخر (Mocking) و تست‌پذیری
 */
interface SearchServiceInterface
{
    public function searchAdmin(string $query, int $limit = 5, int $offset = 0): array;
    
    public function searchUser(string $query, int $userId, int $limit = 5, int $offset = 0): array;
    
    public function searchModules($modules, array $filters = [], int $limit = 20, int $offset = 0): array;
    
    public function invalidateModuleCache(string $module): void;
    
    public function searchBanners(string $q, array $filters = [], int $limit = 20, int $offset = 0): array;
    
    public function searchContent(string $q, array $filters = [], int $limit = 20, int $offset = 0): array;
    public function searchContentForExport(string $q, array $filters = [], int $limit = 1000, int $offset = 0): array;
    
    public function searchTokens(string $q, array $filters = [], int $limit = 20, int $offset = 0): array;
    
    public function searchEmails(string $q, array $filters = [], int $limit = 20, int $offset = 0): array;
    
    public function searchAdTasks(string $q, array $filters = [], int $limit = 20, int $offset = 0): array;
    
    public function searchInvestments(string $q, array $filters = [], int $limit = 20, int $offset = 0): array;
    
    public function searchTickets(string $q, array $filters = [], int $limit = 20, int $offset = 0): array;
    
    public function searchInfluencers(string $q, array $filters = [], int $limit = 20, int $offset = 0): array;

    public function searchAdminModule(string $module, string $q, array $filters = [], int $limit = 20, int $offset = 0): array;

    public function registeredAdminModules(): array;
}
