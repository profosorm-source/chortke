<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Contracts\CacheInterface;
use App\Contracts\LoggerInterface;

/**
 * CacheInvalidationService - مرکز مدیریت باطل‌سازی کش‌های سیستم
 */
class CacheInvalidationService
{
    private CacheInterface $cache;
    private LoggerInterface $logger;
    public function __construct(
        CacheInterface $cache,
        LoggerInterface $logger
    ) {        $this->cache = $cache;
        $this->logger = $logger;
}

    /**
     * ثبت متدهای باطل‌سازی به عنوان شنونده رویدادها (Event Subscriber)
     */
    public function subscribe(\Core\EventDispatcher $dispatcher): void
    {
        $dispatcher->listen('wallet.updated', [$this, 'onWalletUpdated']);
        $dispatcher->listen(\App\Events\ScoreUpdatedEvent::class, [$this, 'onScoreUpdated']);
        $dispatcher->listen('search.invalidated', [$this, 'onSearchInvalidated']);
        $dispatcher->listen('module.search.invalidated', [$this, 'onModuleSearchInvalidated']);
        $dispatcher->listen('payment.status_changed', [$this, 'onPaymentUpdated']);
        $dispatcher->listen('user.profile_updated', [$this, 'onUserProfileUpdated']);
        $dispatcher->listen('user.kyc_updated', [$this, 'onUserKycUpdated']);
    }

    public function onPaymentUpdated($event): void
    {
        $data = $event instanceof \Core\Event ? $event->getData() : (array)$event;
        if (!empty($data['payment_id'])) {
            $this->invalidatePayment((int)$data['payment_id']);
        }
    }

    public function onUserProfileUpdated($event): void
    {
        $data = $event instanceof \Core\Event ? $event->getData() : (array)$event;
        if (!empty($data['user_id'])) {
            $this->invalidateUser((int)$data['user_id']);
        }
    }

    public function onUserKycUpdated($event): void
    {
        $data = $event instanceof \Core\Event ? $event->getData() : (array)$event;
        if (!empty($data['user_id'])) {
            $this->invalidateUser((int)$data['user_id']);
        }
    }

    public function onWalletUpdated($event): void
    {
        $data = $event instanceof \Core\Event ? $event->getData() : (array)$event;
        if (!empty($data['user_id'])) {
            $this->invalidateWallet((int)$data['user_id']);
        }
    }

    public function onScoreUpdated($event): void
    {
        $data = $event instanceof \Core\Event ? $event->getData() : (array)$event;
        if (!empty($data['user_id']) && !empty($data['domain'])) {
            $this->invalidateScore((int)$data['user_id'], $data['domain']);
        }
    }

    public function onSearchInvalidated($event): void
    {
        $this->invalidateSearch();
    }

    public function onModuleSearchInvalidated($event): void
    {
        $data = $event instanceof \Core\Event ? $event->getData() : (array)$event;
        if (!empty($data['module'])) {
            $this->invalidateModuleSearch($data['module']);
        }
    }

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
            $this->cache->delete($key);
        }

        // استفاده از تگ برای پاکسازی لیست تراکنش‌ها (اگر درایور پشتیبانی کند)
        $this->cache->tags(["wallet_tx_{$userId}"])->flush();

        $this->logger->info('cache.wallet_invalidated', ['user_id' => $userId]);
    }

    public function invalidateModuleSearch(string $module): void
    {
        $this->cache->tags([$module, "search:{$module}", "search:domain:{$module}"])->flush();
    }

    public function invalidateScore(int $userId, string $domain): void
    {
        $this->cache->delete("score:user:{$userId}:{$domain}");
        $this->cache->delete("temp_{$domain}_score:{$userId}");
    }

    public function invalidateSearch(): void
    {
        $this->cache->tags(['search'])->flush();
    }

    public function invalidatePayment(int $paymentId): void
    {
        $this->cache->delete("payment:status:{$paymentId}");
        $this->cache->delete("payment:details:{$paymentId}");
        $this->cache->tags(["payment_{$paymentId}"])->flush();
        $this->logger->info('cache.payment_invalidated', ['payment_id' => $paymentId]);
    }

    public function invalidateUser(int $userId): void
    {
        $this->cache->delete("user_settings:{$userId}");
        $this->cache->delete("profile:{$userId}");
        $this->cache->tags(["user_{$userId}"])->flush();
        $this->logger->info('cache.user_invalidated', ['user_id' => $userId]);
    }

    public function invalidateFeatureFlag(?string $featureName = null): void
    {
        if ($featureName) {
            $this->cache->tags(['feature_flag'])->forget($featureName);
        } else {
            $this->cache->tags(['feature_flag'])->flush();
        }
        $this->logger->info('cache.feature_flag_invalidated', ['feature' => $featureName ?? 'all']);
    }
}