<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * 🚀 UPG-01: ModuleSearchProvider - تأمین‌کننده اختصاصی جستجوهای ماژولار به صورت Tagged Cache
 */
class ModuleSearchProvider extends BaseSearchProvider implements \App\Contracts\SearchProviderInterface
{
    private ModuleSearchGateway $gateway;
    private AdminSearchGateway $adminSearchGateway;
    private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation;
    public function __construct(
        \App\Contracts\CacheInterface $cache,
        \App\Contracts\LoggerInterface $logger,
        ModuleSearchGateway $gateway,
        AdminSearchGateway $adminSearchGateway,
        ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null,
        ?\App\Services\Settings\AppSettings $appSettings = null
    ) {        $this->gateway = $gateway;
        $this->adminSearchGateway = $adminSearchGateway;
        $this->cacheInvalidation = $cacheInvalidation;

        parent::__construct($context, $cache, $logger, $settingService);
    }

    public function supports(string $scope): bool
    {
        return $scope === 'module';
    }

    public function search(\App\Services\Search\SearchQuery $query): \App\Services\Search\SearchResult
    {
        $modules = $query->getFilters()['modules'] ?? [];
        $result = $this->searchModules($modules, $query->getFilters(), $query->getLimit(), $query->getOffset());

        $total = 0;
        foreach ($result as $moduleResult) {
            if (is_array($moduleResult)) {
                $total += (int)($moduleResult['total'] ?? count($moduleResult['items'] ?? $moduleResult));
            }
        }

        return new \App\Services\Search\SearchResult(
            $result,
            $total,
            []
        );
    }

    /**
     * جستجوی اختصاصی ماژول‌های سیستم
     */
    public function searchModules(
        $modules,
        array $filters = [],
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0
    ): array {
        $this->logSearch('module', json_encode($filters), null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);
        $modules = is_array($modules) ? $modules : [$modules];

        $results = [];
        $registeredModules = $this->adminSearchGateway->registeredModules();

        foreach ($modules as $module) {
            if (!in_array($module, $registeredModules, true)) {
                continue;
            }

            $cacheKey = $this->generateCacheKey($module, $filters, $limit, $offset);
            $tags = $this->searchTags('search:module', "search:{$module}", $module);
            $cached = $this->cacheGet($cacheKey, $tags);

            if ($cached !== null) {
                $results[$module] = $cached;
                continue;
            }

            // Proxy directly to dynamic AdminSearchGateway to unify pagination, index-usage, and full text search
            $searchResult = $this->gateway->searchRegistered($module, '', $filters, $limit, $offset);

            // Use unified cache TTL from config, fallback to 15 mins
            $ttl = $this->getCacheTTL('search');
            $this->cacheSetSeconds($cacheKey, $searchResult, $ttl, $tags);
            $results[$module] = $searchResult;
        }

        return $results;
    }

    /**
     * پاک‌سازی کش ماژول‌ها
     */
    public function invalidateModuleCache(string $module): void
    {
        if (!in_array($module, $this->adminSearchGateway->registeredModules(), true)) {
            return;
        }

        try {
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateModuleSearch($module);
            } else {
                $this->cache->tags([$module])->flush();
                $this->cache->tags(["search:{$module}"])->flush();
            }
            $this->logger->info("search.cache_invalidated", [
                'module' => $module,
                'driver' => $this->cache->driver()
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("search.cache_invalidation_failed", [
                'module' => $module,
                'error' => $e->getMessage()
            ]);
        }
    }
}
