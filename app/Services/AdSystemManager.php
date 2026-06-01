<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AdSystemContract;
use App\Contracts\AdsRepositoryInterface;
use App\Contracts\LoggerInterface;
use Core\Database;
use RuntimeException;

/**
 * AdSystemManager — مدیریت یکپارچه تمام سیستم‌های تبلیغاتی
 * 
 * این کلاس با استفاده از Strategy Pattern تمام سیستم‌های تبلیغاتی را یکسان‌سازی می‌کند
 * و به Controller‌ها کمک می‌کند بدون نگرانی درباره نوع سیستم، عمل انجام دهند.
 */
class AdSystemManager
{
    private array $adapters = [];
    private AdsRepositoryInterface $adsRepository;


    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        array $adapters,
        AdsRepositoryInterface $adsRepository
    )
    {        $this->db = $db;
        $this->logger = $logger;

        
        $this->adapters = $adapters;
        $this->adsRepository = $adsRepository;
        }

    /**
     * دریافت Adapter برای نوع سیستم
     * 
     * @param string $type نوع سیستم (custom_task, seo, banner, ...)
     * @return AdSystemContract
     * @throws RuntimeException
     */
    public function getAdapter(string $type): AdSystemContract
    {
        if (!isset($this->adapters[$type])) {
            // M38 Fix: لاگ کردن محرمانه لیست پلتفرم‌های پشتیبانی‌شده جهت جلوگیری از افشای ساختار معماری به کاربر نهایی
            $this->logger->error('ad_system.adapter_not_found', [
                'requested_type' => $type,
                'supported_types' => array_keys($this->adapters)
            ]);
            throw new RuntimeException("نوع سیستم تبلیغاتی نامعتبر است.");
        }

        $adapter = $this->adapters[$type];
        if (!($adapter instanceof AdSystemContract)) {
            throw new RuntimeException("Adapter for type '{$type}' باید AdSystemContract را پیاده‌سازی کند");
        }

        return $adapter;
    }

    /**
     * ایجاد آگهی/تسک جدید
     */
    public function create(string $type, int $userId, array $data): array
    {
        return $this->getAdapter($type)->create($userId, $data);
    }

    /**
     * بررسی اعتبار داده‌های آگهی
     */
    public function validateAd(string $type, array $data, bool $isUpdate = false): array
    {
        return $this->getAdapter($type)->validate($data, $isUpdate);
    }

    /**
     * بررسی انقضای آگهی
     */
    public function isExpired(string $type, int $adId): bool
    {
        return $this->getAdapter($type)->isExpired($adId);
    }

    /**
     * محاسبه هزینه/کمیسیون سایت
     */
    public function calculateCost(string $type, float $amount, array $context = []): float
    {
        return $this->getAdapter($type)->calculateCost($amount, $context);
    }

    /**
     * پردازش پرداخت/کسب بودجه
     */
    public function processPayment(string $type, int $adId, int $userId, float $amount, string $currency): array
    {
        return $this->getAdapter($type)->processPayment($adId, $userId, $amount, $currency);
    }

    /**
     * ردیابی تعاملات
     */
    public function track(string $type, int $adId, string $eventType, ?int $userId = null): array
    {
        return $this->getAdapter($type)->track($adId, $eventType, $userId);
    }

    /**
     * دریافت وضعیت آگهی
     */
    public function getStatus(string $type, int $adId): ?array
    {
        return $this->getAdapter($type)->getStatus($adId);
    }

    /**
     * دریافت دسته‌بندی انواع سیستم‌ها
     */
    public function getSupportedTypes(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * بررسی اینکه نوع پشتیبانی شده است
     */
    public function isSupported(string $type): bool
    {
        return isset($this->adapters[$type]);
    }
    /**
     * دریافت آگهی‌های کاربر به همراه خلاصه
     */
    public function getUserAds(int $userId): array
    {
        $ads = $this->adsRepository->where('user_id', '=', $userId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'DESC')
            ->get();

        return $ads ?? [];
    }

    /**
     * دریافت خلاصه آمار آگهی‌های کاربر
     */
    public function getAdSummary(int $userId): array
    {
        $summary = $this->adsRepository->where('user_id', '=', $userId)
            ->whereNull('deleted_at')
            ->select(['id', 'budget', 'impressions', 'clicks'])
            ->get() ?? [];

        return [
            'total_count' => count($summary),
            'total_invested' => array_sum(array_column((array)$summary, 'budget')),
            'total_impressions' => array_sum(array_column((array)$summary, 'impressions')),
            'total_clicks' => array_sum(array_column((array)$summary, 'clicks'))
        ];
    }

    /**
     * دریافت جزئیات و تاریخچه اجرای آگهی
     */
    public function getAdExecutions(int $adId, string $type): array
    {
        $executions = [];
        
        switch ($type) {
            case 'social_task':
                $executions = $this->db->table('social_task_executions as e')
                    ->select('e.id', 'e.status', 'e.created_at', 'u.full_name as executor')
                    ->join('users u', 'u.id', '=', 'e.executor_id', 'LEFT')
                    ->where('e.ad_id', '=', $adId)
                    ->orderBy('e.created_at', 'DESC')
                    ->limit(50)
                    ->get();
                break;
            
            case 'seo':
                $executions = $this->db->table('seo_executions as e')
                    ->select('e.id', 'e.status', 'e.created_at', 'u.full_name as executor')
                    ->join('users u', 'u.id', '=', 'e.user_id', 'LEFT')
                    ->where('e.ad_id', '=', $adId)
                    ->orderBy('e.created_at', 'DESC')
                    ->limit(50)
                    ->get();
                break;

            case 'custom_task':
                $executions = $this->db->table('custom_task_submissions as e')
                    ->select('e.id', 'e.status', 'e.created_at', 'u.full_name as executor')
                    ->join('users u', 'u.id', '=', 'e.user_id', 'LEFT')
                    ->where('e.task_id', '=', $adId)
                    ->orderBy('e.created_at', 'DESC')
                    ->limit(50)
                    ->get();
                break;
        }
        
        return $executions ?? [];
    }

    /**
     * تغییر وضعیت فعال/غیرفعال آگهی
     */
    public function toggleAdStatus(int $adId, int $userId): array
    {
        $ad = $this->adsRepository->find($adId);
        
        if (!$ad || (int)$ad->user_id !== $userId) {
            return ['success' => false, 'message' => 'آگهی متعلق به شما یافت نشد.'];
        }

        $newActive = (int)$ad->is_active === 1 ? 0 : 1;
        $this->adsRepository->update($adId, ['is_active' => $newActive]);

        $msg = $newActive ? 'آگهی مجدداً فعال شد.' : 'آگهی به صورت موقت متوقف شد.';
        return ['success' => true, 'message' => $msg, 'is_active' => $newActive];
    }
}
