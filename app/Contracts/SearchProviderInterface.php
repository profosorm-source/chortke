<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;

/**
 * SearchProviderInterface — قرارداد توسعه‌پذیری موتور جستجو
 * 
 * با استفاده از این قرارداد، هر ماژول جدید می‌تواند سرویس جستجوی خود را 
 * بدون نیاز به تغییر در SearchOrchestrator یا هسته سیستم ثبت کند.
 */
interface SearchProviderInterface
{
    /**
     * بررسی پشتیبانی از این Scope خاص
     */
    public function supports(string $scope): bool;

    /**
     * انجام عملیات جستجو بر اساس شیء SearchQuery
     */
    public function search(SearchQuery $query): SearchResult;
}
