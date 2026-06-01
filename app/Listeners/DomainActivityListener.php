<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Gamification\XpService;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\ReferralService;
use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;
use App\Services\Cache\CacheInvalidationService;
use App\Services\Notification\NotificationTracker;
use App\Services\Notification\NotificationAnalyticsService;
use App\Services\AuditTrail;
use App\Services\User\UserService;
use App\Services\OutboxService;
use App\Services\Shared\IdempotencyService;

/**
 * DomainActivityListener
 * تجمیع‌کننده تمام Side Effectsهای سیستم برای جلوگیری از Coupling در سرویس‌ها
 */
class DomainActivityListener
{
    private XpService $xpService;
    private WalletServiceInterface $walletService;
    private ReferralService $referralService;
    private NotificationServiceInterface $notificationService;
    private CacheInvalidationService $cacheInvalidation;
    private NotificationTracker $tracker;
    private NotificationAnalyticsService $analytics;
    private AuditTrail $auditTrail;
    private LoggerInterface $logger;
    private UserService $userService;
    private ?OutboxService $outbox;
    private IdempotencyService $idempotencyService;
    public function __construct(
        XpService $xpService,
        WalletServiceInterface $walletService,
        ReferralService $referralService,
        NotificationServiceInterface $notificationService,
        CacheInvalidationService $cacheInvalidation,
        NotificationTracker $tracker,
        NotificationAnalyticsService $analytics,
        AuditTrail $auditTrail,
        LoggerInterface $logger,
        UserService $userService,
        ?OutboxService $outbox = null,
        IdempotencyService $idempotencyService
    ) {        $this->xpService = $xpService;
        $this->walletService = $walletService;
        $this->referralService = $referralService;
        $this->notificationService = $notificationService;
        $this->cacheInvalidation = $cacheInvalidation;
        $this->tracker = $tracker;
        $this->analytics = $analytics;
        $this->auditTrail = $auditTrail;
        $this->logger = $logger;
        $this->userService = $userService;
        $this->outbox = $outbox;
        $this->idempotencyService = $idempotencyService;
}

    public function handle(string|object $event, array $data = []): void
    {
        if (is_object($event)) {
            $eventData = method_exists($event, 'getData') ? $event->getData() : [];
            if (method_exists($event, 'getName')) {
                $eventName = $event->getName();
            } else {
                // تبدیل نام کلاس Event مانند WithdrawalCreatedEvent -> withdrawal.created
                $ref = new \ReflectionClass($event);
                $short = $ref->getShortName();
                $short = preg_replace('/Event$/', '', $short);
                $eventName = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1.$2', $short));
            }
        } else {
            $eventName = $event;
            $eventData = $data;
        }

        try {
            match ($eventName) {
                'content.created'     => $this->handleContentCreated($eventData),
                'content.approved'    => $this->handleContentApproval($eventData),
                'prediction.resolved' => $this->handlePredictionResolved($eventData),
                'lottery.won'         => $this->handleLotteryWon($eventData),
                'investment.created'  => $this->handleInvestmentCreated($eventData),
                'investment.profit_applied' => $this->handleInvestmentProfit($eventData),
                'investment.withdrawal_approved' => $this->handleInvestmentWithdrawal($eventData, true),
                'investment.withdrawal_rejected' => $this->handleInvestmentWithdrawal($eventData, false),
                'content.rated'       => $this->handleContentRating($eventData),
                'influencer.order_completed' => $this->handleInfluencerOrderCompleted($eventData),
                'influencer.order_rejected'  => $this->handleInfluencerOrderRejected($eventData),
                'notification.sent'   => $this->handleNotificationSent($eventData),
                'deposit.manual_created' => $this->handleManualDepositCreated($eventData),
                'deposit.manual_approved' => $this->handleManualDepositApproved($eventData),
                'deposit.manual_rejected' => $this->handleManualDepositRejected($eventData),
                'kyc.status_changed'  => $this->handleKycStatusChanged($eventData),
                'withdrawal.created'  => $this->handleWithdrawalCreated($eventData),
                'withdrawal.approved' => $this->handleWithdrawalApproved($eventData),
                'score.updated'       => $this->handleScoreUpdated($eventData),
                'escrow.state_changed' => $this->handleEscrowStateChanged($eventData),
                'lottery.participated' => $this->handleLotteryParticipation($eventData),
                'prediction.bet_placed' => $this->handlePredictionBet($eventData),
                'queue.failed'        => $this->handleQueueFailed($eventData),
                'reconciliation.failed' => $this->handleReconciliationFailed($eventData),
                default               => null
            };
        } catch (\Throwable $e) {
            $this->logger->error("Error handling side effect for {$eventName}", [
                'channel' => 'event_listener',
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleReconciliationFailed(array $data): void
    {
        $this->logger->error('financial.reconciliation_failed', $data);
        
        $this->notificationService->send(
            1, // ادمین کل
            'critical_alert',
            '🔴 خطای تطبیق موجودی (Reconciliation)',
            "مغایرت در واریز دستی به شماره " . ($data['id'] ?? 'N/A') . " شناسایی شد. مبلغ: " . ($data['amount'] ?? '0'),
            $data, null, null, 'high'
        );
    }

    private function handleContentCreated(array $data): void
    {
        $this->xpService->award($data['user_id'], 'CONTENT_CREATION', 5.0, "new_content_{$data['content_id']}");
    }

    private function handleContentApproval(array $data): void
    {
        $this->xpService->award($data['user_id'], $data['context'], 10.0, 'محتوا تایید شد');

        $contentId = (int)($data['content_id'] ?? 0);
        $idemKey = 'content_reward:' . $contentId;

        if ($this->outbox) {
            $payload = [
                'user_id' => (int)$data['user_id'],
                'amount' => (float)$data['amount'],
                'currency' => $data['currency'] ?? 'irt',
                'metadata' => [
                    'type' => 'content_reward',
                    'content_id' => $contentId,
                    'description' => 'پاداش تأیید محتوا',
                    'idempotency_key' => $idemKey,
                ],
            ];
            $this->outbox->record('content', $contentId, 'wallet.deposit.requested', $payload);
        } else {
            $payload = ['user_id' => (int)$data['user_id'], 'amount' => (float)$data['amount'], 'currency' => 'irt', 'metadata' => ['type' => 'content_reward', 'content_id' => $contentId]];
            $this->idempotencyService->executeWithTransaction(
                'wallet.deposit',
                (int)$data['user_id'],
                $payload,
                function () use ($payload) {
                    return $this->walletService->deposit($payload['user_id'], (string)$payload['amount'], $payload['currency'], $payload['metadata']);
                },
                $idemKey
            );
        }

        $this->referralService->processCommission($data['user_id'], $data['amount'], 'content_approval');
        $this->cacheInvalidation->invalidateWallet($data['user_id']);
    }

    private function handlePredictionResolved(array $data): void
    {
        $xp = $data['is_winner'] ? 25.0 : 5.0;
        $this->xpService->award($data['user_id'], 'PREDICTION', $xp, "game_{$data['game_id']}");
        // Integration با سیستم Trust (ScoreService)
        $trustDelta = $data['is_winner'] ? 1.0 : -0.5;
        $this->xpService->applyDelta('user', $data['user_id'], \App\Enums\ScoreDomain::Fraud->value, $trustDelta, 'prediction_result');
    }

    private function handleLotteryWon(array $data): void
    {
        $this->xpService->award($data['user_id'], 'LOTTERY_WIN', 100.0, "round_{$data['round_id']}");
    }

    private function handleInvestmentCreated(array $data): void
    {
        $this->xpService->award($data['user_id'], 'INVESTMENT', 50.0, "inv_{$data['investment_id']}");
        $this->notificationService->send($data['user_id'], 'investment_created', 'سرمایه‌گذاری ثبت شد', 'سرمایه‌گذاری شما با موفقیت انجام شد.');
        $this->cacheInvalidation->invalidateWallet($data['user_id']);
    }

    private function handleInvestmentProfit(array $data): void
    {
        $this->notificationService->send($data['user_id'], 'investment_profit', 'گزارش سود', "سود دوره {$data['period']} اعمال شد.");
        $this->cacheInvalidation->invalidateWallet($data['user_id']);
    }

    private function handleInvestmentWithdrawal(array $data, bool $approved): void
    {
        $title = $approved ? 'برداشت تایید شد' : 'برداشت رد شد';
        $msg = $approved ? "مبلغ {$data['amount']} USDT به کیف پول شما واریز شد." : "دلیل: {$data['reason']}";
        
        $this->notificationService->send($data['user_id'], 'investment_withdrawal', $title, $msg);
        $this->cacheInvalidation->invalidateWallet($data['user_id']);
    }

    private function handleContentRating(array $data): void
    {
        $this->xpService->award($data['user_id'], $data['context'], 2.0, 'ثبت امتیاز برای محتوا');
    }

    private function handleInfluencerOrderCompleted(array $data): void
    {
        $this->xpService->award($data['influencer_user_id'], 'INFLUENCER', 10.0, "order_{$data['order_id']}");
        $this->cacheInvalidation->invalidateWallet($data['influencer_user_id']);
    }

    private function handleInfluencerOrderRejected(array $data): void
    {
        $this->logger->info("Influencer order rejected side effects", $data);
        $this->cacheInvalidation->invalidateWallet($data['customer_id']);
    }


    private function handleManualDepositCreated(array $data): void
    {
        $this->logger->info('manual_deposit.created_event', array_merge($data, ['channel' => 'event_listener']));
    }

    private function handleManualDepositApproved(array $data): void
    {
        $this->auditTrail->record('deposit.approved', $data['user_id'], [
            'deposit_id' => $data['deposit_id'],
            'amount' => $data['amount'],
            'transaction_id' => $data['transaction_id']
        ], $data['admin_id']);

        $this->notificationService->send(
            $data['user_id'],
            'deposit_success',
            'واریز تأیید شد',
            "مبلغ {$data['amount']} با موفقیت به حساب شما واریز گردید."
        );
        $this->cacheInvalidation->invalidateWallet($data['user_id']);
    }

    private function handleManualDepositRejected(array $data): void
    {
        $this->auditTrail->record('deposit.rejected', $data['user_id'], [
            'deposit_id' => $data['deposit_id'],
            'reason' => $data['reason']
        ], $data['admin_id']);

        $this->notificationService->send(
            $data['user_id'],
            'deposit_rejected',
            'واریز رد شد',
            "درخواست واریز شما رد شد. دلیل: {$data['reason']}"
        );
    }

    private function handleKycStatusChanged(array $data): void
    {
        $status = $data['new_status'];
        $userId = $data['user_id'];

        // 🛡️ Decoupling: به‌روزرسانی وضعیت کاربر در دامین User
        $this->userService->update($userId, ['kyc_status' => $status]);

        $this->auditTrail->record("kyc.{$status}", $userId, $data, $data['admin_id'] ?? null);

        if ($status === 'verified') {
            $this->notificationService->send($userId, 'kyc_verified', 'احراز هویت تایید شد', 'مدارک شما با موفقیت تایید گردید.');
            $this->xpService->award($userId, 'KYC', 50.0, 'احراز هویت موفق');
        } elseif ($status === 'rejected') {
            $this->notificationService->send($userId, 'kyc_rejected', 'احراز هویت رد شد', "دلیل رد: " . ($data['reason'] ?? 'نقص مدارک'));
        }

        $this->cacheInvalidation->invalidateWallet($userId);
    }

    private function handleWithdrawalCreated(array $data): void
    {
        $this->auditTrail->record('withdrawal.created', $data['user_id'], $data);
        $this->notificationService->send($data['user_id'], 'withdrawal_pending', 'درخواست برداشت ثبت شد', 'درخواست شما در صف بررسی قرار گرفت.');
    }

    private function handleWithdrawalApproved(array $data): void
    {
        $this->notificationService->send($data['user_id'], 'withdrawal_approved', 'برداشت تایید شد', "مبلغ {$data['amount']} به حساب شما واریز گردید.");
        $this->xpService->award($data['user_id'], 'FINANCIAL', 5.0, "withdrawal_{$data['id']}");
    }

    private function handleScoreUpdated(array $data): void
    {
        if ($data['entity_type'] === 'user') {
            // 🏆 ارتقاء سطح خودکار پس از تغییر امتیاز
            $this->xpService->checkLevelUp((int)$data['aggregate_id']);
        }
    }

    private function handleEscrowStateChanged(array $data): void
    {
        if ($data['new_status'] === 'released') {
            $this->notificationService->send($data['seller_id'] ?? 0, 'escrow_released', 'آزاد سازی وجه', 'مبلغ معامله به حساب شما منتقل شد.');
        }
    }

    private function handleLotteryParticipation(array $data): void
    {
        $this->auditTrail->record('lottery.participation', $data['user_id'], $data);
        // افزایش امتیاز اعتماد برای شرکت در فعالیت‌های سایت
        $this->xpService->applyDelta('user', $data['user_id'], \App\Enums\ScoreDomain::Fraud->value, 0.1, 'lottery_participation');
    }

    private function handlePredictionBet(array $data): void
    {
        $this->xpService->award($data['user_id'], 'PREDICTION', 2.0, "bet_{$data['game_id']}");
    }
    

    private function handleNotificationSent(array $data): void
    {
        $this->tracker->track($data['notification_id'], $data['recipient_id'], $data['status']);
        $this->analytics->recordDelivery($data['type'], $data['channel']);
    }

    /**
     * مدیریت هشدارهای مربوط به شکست جاب‌ها در صف (DLQ Alerting)
     */
    private function handleQueueFailed(array $data): void
    {
        $jobName = $data['job_name'] ?? 'Unknown Job';
        $error = $data['error'] ?? 'No error details provided';
        
        // لیست جاب‌های بحرانی که نیاز به هشدار فوری دارند (بیشتر موارد مالی)
        $criticalPatterns = [
            'InvestmentProfit',
            'ManualDeposit',
            'Withdrawal',
            'Wallet',
            'LedgerReconciliation'
        ];

        $isCritical = false;
        foreach ($criticalPatterns as $pattern) {
            if (str_contains($jobName, $pattern)) {
                $isCritical = true;
                break;
            }
        }

        if ($isCritical) {
            $this->logger->critical('queue.dlq.financial_failure', ['job' => $jobName, 'error' => $error]);

            // ارسال هشدار به ادمین سیستم (فرض بر ادمین ID 1)
            $this->notificationService->send(
                1,
                'critical_alert',
                '⚠️ خطای بحرانی در عملیات صف مالی',
                "جاب {$jobName} با شکست مواجه شد و به صف خطا منتقل گردید.\nخطا: {$error}",
                ['job' => $jobName],
                null,
                null,
                'high'
            );
        }
    }
}