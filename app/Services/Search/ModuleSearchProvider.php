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
        private ModuleSearchGateway $gateway
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

        foreach ($modules as $module) {
            if (!in_array($module, self::MODULES, true)) {
                continue;
            }

            $cacheKey = $this->generateCacheKey($module, $filters, $limit, $offset);
            $tags = $this->searchTags('search:module', "search:module:{$module}", $module);
            $cached = $this->cacheGet($cacheKey, $tags);

            if ($cached !== null) {
                $results[$module] = $cached;
                continue;
            }

            $searchResult = match ($module) {
                'social_task'    => $this->searchSocialTasks($filters, $limit, $offset),
                'influencer'     => $this->searchInfluencersModule($filters, $limit, $offset),
                'vitrine'        => $this->searchVitrine($filters, $limit, $offset),
                'custom_task'    => $this->searchCustomTasks($filters, $limit, $offset),
                'investment'     => $this->searchInvestments($filters, $limit, $offset),
                'prediction'     => $this->searchPredictions($filters, $limit, $offset),
                'lottery'        => $this->searchLotteries($filters, $limit, $offset),
                'content'        => $this->searchContents($filters, $limit, $offset),
                'coupon'         => $this->searchCoupons($filters, $limit, $offset),
                'ticket'         => $this->searchTickets($filters, $limit, $offset),
                'seo_ad'         => $this->searchSeoAds($filters, $limit, $offset),
                'direct_message' => $this->searchDirectMessages($filters, $limit, $offset),
                default          => []
            };

            $this->cacheSetSeconds($cacheKey, $searchResult, self::CACHE_TTL_SECONDS, $tags);
            $results[$module] = $searchResult;
        }

        return $results;
    }

    /**
     * پاک‌سازی کش ماژول‌ها
     */
    public function invalidateModuleCache(string $module): void
    {
        if (!in_array($module, self::MODULES, true)) {
            return;
        }

        try {
            $this->cache->tags([$module])->flush();
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

    // Internal delegators

    private function searchSocialTasks(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchSocialTasks($f, $limit, $offset);
    }

    private function searchInfluencersModule(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchInfluencers($f, $limit, $offset);
    }

    private function searchVitrine(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchVitrine($f, $limit, $offset);
    }

    private function searchCustomTasks(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchCustomTasks($f, $limit, $offset);
    }

    private function searchInvestments(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchInvestments($f, $limit, $offset);
    }

    private function searchPredictions(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchPredictions($f, $limit, $offset);
    }

    private function searchLotteries(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchLotteries($f, $limit, $offset);
    }

    private function searchContents(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchContents($f, $limit, $offset);
    }

    private function searchCoupons(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchCoupons($f, $limit, $offset);
    }

    private function searchTickets(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchTickets($f, $limit, $offset);
    }

    private function searchSeoAds(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchSeoAds($f, $limit, $offset);
    }

    private function searchDirectMessages(array $f, int $limit, int $offset): array
    {
        return $this->gateway->searchDirectMessages($f, $limit, $offset);
    }
}
