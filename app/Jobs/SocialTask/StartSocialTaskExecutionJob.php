<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

class StartSocialTaskExecutionJob
{
    public function __construct(
        private \App\Models\CryptoDeposit $model,
        private \App\Services\Settings\AppSettings $appSettings,
        private \App\Contracts\LoggerInterface $logger
    ) {}

    public function handle(int $userId, int $adId, array $context = []): array
    {
        $pipeline = new \Core\Pipeline(\Core\Container::getInstance());
        $payload = [
            'user_id' => $userId,
            'action' => 'task.social',
            'context' => [
                'task_id'    => $adId,
                'action'     => 'start',
                'ip'         => $context['ip'] ?? '',
                'user_agent' => $context['user_agent'] ?? ''
            ]
        ];

        return $pipeline->send($payload)->through([\App\Middleware\TaskFraudGuardMiddleware::class])->then(function($passable) use ($userId, $adId, $context) {

        try {
            // [SAGA REFACTOR] Removed transaction wrapper to allow distributed execution
            return (function() use ($userId, $adId, $context) {
                // قفل کردن ردیف با FOR UPDATE برای جلوگیری از Race Condition
                $ad = $this->model->getAdById($adId, true);
    
                if (!$ad || $ad->status !== 'active' || $ad->remaining_count <= 0) {
                    return ['success' => false, 'message' => 'تسک موجود نیست یا ظرفیت تکمیل شده'];
                }
    
                // چک کردن اینکه قبلا انجام نشده باشد
                $existing = $this->model->getExecutionWithAd($adId, $userId);
                if ($existing && !in_array($existing->status, ['expired', 'cancelled', 'rejected'], true)) {
                    return ['success' => false, 'message' => 'شما قبلاً این تسک را انجام داده‌اید یا در حال انجام آن هستید'];
                }
    
                // M36 Fix: جایگزینی فراخوانی ریت‌لیمیتر قدیمی با سیستم جدید و استاندارد سیاست محدودیت
                if (!$this->rateLimiter->check('task_submit', $userId)) {
                    return ['success' => false, 'message' => 'محدودیت تعداد تسک در ساعت'];
                }
    
                $expectedTimeMap = $this->appSettings->get('social_task_expected_times', self::DEFAULT_TASK_EXPECTED_TIME);
                $expectedTime = $expectedTimeMap[$ad->task_type] ?? 60;
    
                if ($this->model->decrementAdSlots($adId) < 1) {
                    return ['success' => false, 'message' => 'ظرفیت تکمیل شده'];
                }
    
                $execId = $this->model->createExecution([
                    'ad_id' => $adId,
                    'executor_id' => $userId,
                    'ip_address' => $context['ip'] ?? '',
                    'user_agent' => $context['user_agent'] ?? '',
                    'expected_time' => $expectedTime
                ]);
    
                return ['success' => true, 'execution_id' => $execId, 'expected_time' => $expectedTime, 'target_url' => $ad->target_url, 'task_type' => $ad->task_type];
            });
        } catch (\Throwable $e) {
            $this->logger->error('social.start_execution_failed', [
                'user_id' => $userId,
                'ad_id' => $adId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی رخ داد'];
        }
        });
    }
}
