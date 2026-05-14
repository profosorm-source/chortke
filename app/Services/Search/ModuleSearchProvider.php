<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\SocialTask\SocialTaskService;
use App\Services\InfluencerService;
use App\Services\VitrineService;

/**
 * 🚀 UPG-01: ModuleSearchProvider - تأمین‌کننده اختصاصی جستجوهای ماژولار به صورت Tagged Cache
 */
class ModuleSearchProvider extends BaseSearchProvider
{
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
            $cached = $this->cache->tags([$module])->get($cacheKey);

            if ($cached !== null) {
                $results[$module] = $cached;
                continue;
            }

            $searchResult = match ($module) {
                'social_task' => $this->searchSocialTasks($filters, $limit, $offset),
                'influencer'  => $this->searchInfluencersModule($filters, $limit, $offset),
                'vitrine'     => $this->searchVitrine($filters, $limit, $offset),
                default       => []
            };

            $this->cache->tags([$module])->put($cacheKey, $searchResult, self::CACHE_TTL_MINUTES);
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
        return $this->getService(SocialTaskService::class)->searchSocialTasks($f, $limit, $offset);
    }

    private function searchInfluencersModule(array $f, int $limit, int $offset): array
    {
        return $this->getService(InfluencerService::class)->searchInfluencers($f, $limit, $offset);
    }

    private function searchVitrine(array $f, int $limit, int $offset): array
    {
        return $this->getService(VitrineService::class)->searchVitrine($f, $limit, $offset);
    }
}
