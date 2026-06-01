<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class StartSeoTaskJob
{
    private \Core\TransactionWrapper $transactionWrapper;
    private \App\Services\Seo\AdsSeoService $adsService;
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \App\Services\Seo\AdsSeoService $adsService,
        \App\Services\Settings\AppSettings $appSettings,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->adsService = $adsService;
        $this->appSettings = $appSettings;
        $this->logger = $logger;
}

    public function handle(int $adId, int $userId): array
    {
        try {
            return $this->transactionWrapper->runWithRetry(function() use ($adId, $userId) {
                // قفل کردن آگهی برای جلوگیری از Race Condition
                $ad = $this->adsService->getAdForUpdate($adId);
                
                if (!$ad) {
                    return ['success' => false, 'message' => 'آگهی یافت نشد'];
                }
        
                if ($ad->status !== 'active') {
                    return ['success' => false, 'message' => 'آگهی فعال نیست'];
                }
        
                if ($ad->remaining_budget < $ad->min_payout) {
                    return ['success' => false, 'message' => 'بودجه آگهی تمام شده است'];
                }
        
                // بررسی تکراری
                if ($this->adsService->executionExistsToday($adId, $userId)) {
                    return ['success' => false, 'message' => 'شما امروز این تسک را قبلاً انجام داده‌اید'];
                }
        
                // بررسی محدودیت روزانه کاربر
                $todayCount = $this->adsService->countUserExecutionsToday($userId);
                if ($todayCount >= $ad->max_per_day) {
                    return ['success' => false, 'message' => "حداکثر {$ad->max_per_day} تسک در روز مجاز است"];
                }
        
                // بررسی محدودیت ساعتی
                $hourlyLimit = (int)$this->appSettings->get('seo_max_tasks_per_hour', 5);
                $hourlyCount = $this->adsService->countUserExecutionsLastHour($userId);
                if ($hourlyCount >= $hourlyLimit) {
                    return ['success' => false, 'message' => "حداکثر {$hourlyLimit} تسک در ساعت مجاز است. لطفاً کمی صبر کنید"];
                }
        
                // بررسی IP
                $ip = get_client_ip();
                $ipLimit = (int)$this->appSettings->get('seo_max_ip_tasks_per_hour', 10);
                $ipHourly = $this->adsService->countIpExecutionsLastHour($ip);
                if ($ipHourly >= $ipLimit) {
                    return ['success' => false, 'message' => 'محدودیت IP. لطفاً بعداً تلاش کنید'];
                }
        
                $fingerprint = function_exists('generate_device_fingerprint') 
                    ? generate_device_fingerprint() 
                    : md5($_SERVER['HTTP_USER_AGENT'] ?? '' . $ip);
    
                $sessionId = bin2hex(random_bytes(16));
    
                $this->adsService->createExecution([
                    'ad_id' => $adId,
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'status' => 'started',
                    'ip_address' => $ip,
                    'device_fingerprint' => $fingerprint,
                    'started_at' => date('Y-m-d H:i:s'),
                    'target_keyword' => $ad->keyword,
                ]);
    
                return ['success' => true, 'session_id' => $sessionId];
            });
        } catch (\Exception $e) {
            $this->logger->error('seo_task.start_failed', ['error' => $e->getMessage()]);
            if (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return ['success' => false, 'message' => 'شما امروز این تسک را قبلاً انجام داده‌اید'];
            }
            return ['success' => false, 'message' => 'خطای سیستمی'];
        }
    }
}
