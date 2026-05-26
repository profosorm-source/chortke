<?php

declare(strict_types=1);

namespace App\Services\Cache;

use Core\Cache;
use App\Contracts\LoggerInterface;

/**
 * CacheInvalidationService - مرکز مدیریت باطل‌سازی کش‌های سیستم
 */
class CacheInvalidationService
{
    public function __construct(
        private Cache $cache,
        private LoggerInterface $logger
    ) {}

    /**
     * باطل‌سازی تمام کش‌های مرتبط با کیف پول کاربر
     * رفع نقص: تجمیع کلیدهای پراکنده (Balance, Limits, History)
     */
    public function invalidateWallet(int $userId): void
    {
        $keys = [
            "wallet:balance:{$userId}:irt",
            "wallet:balance:{$userId}:usdt",
            "wallet:limits:{$userId}",
            "wallet:summary:{$userId}",
            "user:financial_status:{$userId}"
        ];

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }

        // استفاده از تگ برای پاکسازی لیست تراکنش‌ها (اگر درایور پشتیبانی کند)
        $this->cache->tags(["wallet_tx_{$userId}"])->flush();

        $this->logger->info('cache.wallet_invalidated', ['user_id' => $userId]);
    }

    public function invalidateModuleSearch(string $module): void
    {
        $this->cache->tags(["search:{$module}"])->flush();
    }

    public function invalidateScore(int $userId, string $domain): void
    {
        $this->cache->forget("score:user:{$userId}:{$domain}");
        $this->cache->forget("temp_{$domain}_score:{$userId}");
    }

    public function invalidateSearch(): void
    {
        $this->cache->tags(['search_results'])->flush();
    }
}