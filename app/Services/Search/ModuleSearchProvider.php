<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * 🚀 UPG-01: ModuleSearchProvider - تأمین‌کننده اختصاصی جستجوهای ماژولار به صورت Tagged Cache
 */
class ModuleSearchProvider extends BaseSearchProvider
{
    public function __construct(
        \App\Models\AdvancedSearch $searchModel,
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        private ModuleSearchGateway $gateway,
        private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null
    ) {
        parent::__construct($searchModel, $cache, $logger);
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
        $gateway = app(\App\Services\Search\AdminSearchGateway::class);
        $registeredModules = $gateway->registeredModules();

        foreach ($modules as $module) {
            if (!in_array($module, $registeredModules, true)) {
                continue;
            }

            $cacheKey = $this->generateCacheKey($module, $filters, $limit, $offset);
            $tags = $this->searchTags('search:module', "search:module:{$module}", $module);
            $cached = $this->cacheGet($cacheKey, $tags);

            if ($cached !== null) {
                $results[$module] = $cached;
                continue;
            }

            // Proxy directly to dynamic AdminSearchGateway to unify pagination, index-usage, and full text search
            $searchResult = $gateway->searchRegistered($module, '', $filters, $limit, $offset);

            // Use unified cache TTL from config, fallback to 15 mins
            $ttl = (int) config('search.cache_ttl', 900);
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
        $gateway = app(\App\Services\Search\AdminSearchGateway::class);
        if (!in_array($module, $gateway->registeredModules(), true)) {
            return;
        }

        try {
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateModuleSearch($module);
            } else {
                $this->cache->tags([$module])->flush();
                $this->cache->tags(["search:module:{$module}"])->flush();
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
