<?php

declare(strict_types=1);

namespace App\Listeners;

use Core\Container;
use App\Services\Cache\CacheInvalidationService;
use App\Contracts\LoggerInterface;

/**
 * InvalidateSearchCacheListener
 * 
 * شنونده رویدادهای اصلی سیستم جهت باطل‌سازی خودکار و پویای کش جستجوی ماژول‌ها.
 */
class InvalidateSearchCacheListener
{
    private CacheInvalidationService $cacheInvalidationService;
    private LoggerInterface $logger;
    public function __construct(
        CacheInvalidationService $cacheInvalidationService,
        LoggerInterface $logger
    ) {        $this->cacheInvalidationService = $cacheInvalidationService;
        $this->logger = $logger;

    }

    public function handle($event): void
    {
        $eventName = '';
        if (is_object($event)) {
            if (method_exists($event, 'getName')) {
                $eventName = (string)$event->getName();
            } else {
                $eventName = get_class($event);
            }
        } else {
            $eventName = (string)$event;
        }
        
        $data = [];
        if (method_exists($event, 'getData')) {
            $data = (array)$event->getData();
        }

        $this->logger->info('search.cache.invalidation.event_received', [
            'event' => $eventName,
            'data' => $data
        ]);

        // نقشه نگاشت نام رویدادها به ماژول‌های جستجو
        $mapping = [
            'ad.created' => 'social_task',
            'ad.updated' => 'social_task',
            'ad.status_changed' => 'social_task',
            'seo_ad.created' => 'seo_ad',
            'seo_ad.approved' => 'seo_ad',
            'seo_ad.rejected' => 'seo_ad',
            'seo_ad.paused' => 'seo_ad',
            \App\Events\TaskCompletedEvent::class => 'custom_task',
            'task.created' => 'custom_task',
            'task.approved' => 'custom_task',
            'prediction.created' => 'prediction',
            'lottery.created' => 'lottery',
            'coupon.created' => 'coupon',
            'ticket.created' => 'ticket',
            'ticket.updated' => 'ticket',
            'content.created' => 'content',
            'content.updated' => 'content',
            'direct_message.created' => 'direct_message',
            'investment.created' => 'investment',
            'investment.updated' => 'investment',
            'investment_plan.updated' => 'investment',
            'bug_report.created' => 'bug_report',
            'escrow.created' => 'escrow',
            'escrow.updated' => 'escrow'
        ];

        // استخراج ماژول از داده‌های رویداد یا از روی جدول نگاشت بالا
        $module = $data['module'] ?? $data['type'] ?? $data['task_type'] ?? null;
        if ($module === null || $module === '') {
            $module = $mapping[$eventName] ?? null;
        }

        if ($module !== null && $module !== '') {
            $this->cacheInvalidationService->invalidateModuleSearch((string)$module);
            $this->cacheInvalidationService->invalidateSearch(); // همیشه کل سرچ را برای اطمینان باطل کن
            $this->logger->info('search.cache.invalidation.completed', [
                'event' => $eventName,
                'module' => $module
            ]);
        } else {
            // در غیر این صورت کل کش مربوط به جستجوها را باطل می‌کنیم
            $this->cacheInvalidationService->invalidateSearch();
            $this->logger->info('search.cache.invalidation.fallback_completed', [
                'event' => $eventName
            ]);
        }
    }
}
