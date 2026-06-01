<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class ProcessSeoTaskAsyncJob
{
    private \App\Services\Seo\AdsSeoService $adsService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private \App\Services\AntiFraud\SeoFraudDetector $fraudDetector;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\SeoPayoutService $payoutService;
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Contracts\WalletServiceInterface $walletService;
    private \Core\EventDispatcher $eventDispatcher;
    public function __construct(
        \App\Services\Seo\AdsSeoService $adsService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        \App\Services\AntiFraud\SeoFraudDetector $fraudDetector,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\SeoPayoutService $payoutService,
        \App\Services\Settings\AppSettings $appSettings,
        \App\Contracts\WalletServiceInterface $walletService,
        \Core\EventDispatcher $eventDispatcher
    ) {        $this->adsService = $adsService;
        $this->fraudGuard = $fraudGuard;
        $this->fraudDetector = $fraudDetector;
        $this->logger = $logger;
        $this->payoutService = $payoutService;
        $this->appSettings = $appSettings;
        $this->walletService = $walletService;
        $this->eventDispatcher = $eventDispatcher;
}

public function handle(int $executionId, int $userId, int $adId, array $engagementData): array
    {


                // قفل گذاری روی آگهی برای بررسی و کسر بودجه
                $ad = $this->adsService->getAdForUpdate($adId);
                
                if (!$ad) {
                    return ['success' => false, 'message' => 'آگهی یافت نشد'];
                }
    
                // 1. اعتبارسنجی داده‌ها
                if (!isset($engagementData['duration'], $engagementData['scroll_depth'], $engagementData['interactions'])) {
                    $this->adsService->rejectExecution($executionId, 'داده‌های تعامل ناقص است');
                    return ['success' => false, 'message' => 'داده‌های تعامل ناقص است'];
                }
    
                // 2. محاسبه امتیاز
                $scores = $this->calculateEngagementScore($engagementData);
    
                // 🛡️ گیت ضدتقلب مرکزی سئو (شامل Session tracking و Risk Decision و تشخیص هوشمند تقلب)
                $risk = $this->fraudGuard->checkAction($userId, 'task.seo', [
                    'ad_id'           => $ad->id,
                    'execution_id'    => $executionId,
                    'session_id'      => $execution->session_id ?? '',
                    'engagement_data' => $engagementData
                ]);
    
                $seoFraud = $risk['details']['seo_fraud'] ?? null;
                $isFraud = $seoFraud && !empty($seoFraud['is_fraud']);
    
                if (empty($risk['allowed']) || $isFraud) {
                    $flags = $seoFraud['flags'] ?? ['blocked_by_security_policy'];
                    $this->adsService->markExecutionAsFraud($executionId, $flags);
                    $this->fraudDetector->addToBlacklist($userId, implode(', ', $flags));
    
                    $this->logger->warning('seo_task.blocked_by_fraud_guard', [
                        'user_id'      => $userId,
                        'execution_id' => $executionId,
                        'reason'       => $risk['reason'] ?? 'fraud_detected'
                    ]);
    
                    return [
                        'success'        => false,
                        'message'        => 'تعامل شما معتبر تشخیص داده نشد',
                        'fraud_detected' => true,
                    ];
                }
    
                // 4. بررسی حداقل امتیاز
                if ($scores['final_score'] < $ad->min_score) {
                    $this->adsService->rejectExecution($executionId, "امتیاز کمتر از حد مجاز ({$ad->min_score})");
                    return [
                        'success' => false,
                        'message' => "امتیاز شما ({$scores['final_score']}) کمتر از حداقل مجاز است",
                    ];
                }
    
                // 5. محاسبه پاداش
                $payoutResult = $this->payoutService->calculatePayout($ad->id, $scores['final_score']);
                if (!$payoutResult['can_pay']) {
                    $this->repository->rejectExecution($executionId, $payoutResult['message']);
                    return [
                        'success' => false,
                        'message' => $payoutResult['message'],
                    ];
                }
    
                $payout = $payoutResult['payout'];
    
                // 6. تکمیل Execution
                if (!$this->adsService->completeExecution($executionId, $scores, $payout)) {
                    throw new \Exception('این تسک قبلاً تکمیل یا لغو شده است');
                }
    
                // 7. کسر از بودجه آگهی
                if (!$this->payoutService->deductFromBudget($ad->id, $payout)) {
                    throw new \Exception('موجودی امانی آگهی کافی نیست');
                }
    
                $adCurrency = strtolower((string)($ad->currency ?? $this->appSettings->get('currency_mode', 'irt')));
                if (!in_array($adCurrency, ['irt', 'usdt'])) {
                    $adCurrency = 'irt';
                }
    
                // 8. واریز به کیف پول کاربر
                $walletResult = $this->walletService->depositInTransaction(
                    $userId,
                    $payout,
                    $adCurrency,
                    [
                        'source' => 'seo_task_reward',
                        'description' => "تسک SEO - {$ad->title}",
                        'execution_id' => $executionId,
                        'ad_id' => $ad->id
                    ]
                );
    
                if (empty($walletResult['success'])) {
                    throw new \Exception('خطا در واریز پاداش');
                }
    
                // 9. پورسانت ریفرال (event-driven via async)
                $userRecord = $this->adsService->getUser($userId);
                if ($userRecord && !empty($userRecord->referred_by)) {
                    $this->eventDispatcher?->dispatchAsync('referral.commission.process', [
                        'referrer_id' => (int)$userRecord->referred_by,
                        'amount' => $payout,
                        'currency' => $adCurrency,
                        'source_user_id' => $userId,
                        'context' => [
                            'action' => 'seo_task_reward',
                            'executor_id' => $userId,
                            'execution_id' => $executionId
                        ]
                    ]);
                }
    
                return [
                    'success' => true,
                    'message' => 'تسک با موفقیت تایید شد و پاداش واریز گردید',
                    'payout' => $payout,
                ];
            }
}
