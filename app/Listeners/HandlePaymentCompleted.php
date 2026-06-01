<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PaymentCompletedEvent;
use App\Services\Gamification\XpService;
use App\Enums\ModuleContext;
use App\Services\Shared\ReferralService;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * HandlePaymentCompleted
 *
 * پس از تکمیل موفق پرداخت آنلاین (واریز از درگاه):
 * ۱. اعطای XP به کاربر متناسب با مبلغ پرداخت
 * ۲. پردازش کمیسیون معرف (Referral Commission)
 * ۳. ارسال notification تبریک واریز
 */
class HandlePaymentCompleted
{
    // نرخ اعطای XP: به ازای هر X تومان، ۱ امتیاز تجربی داده می‌شود
    private const XP_PER_UNIT = 10_000;
    private const MAX_XP_PER_PAYMENT = 500.0;

    private XpService $xpService;
    private ReferralService $referralService;
    private NotificationServiceInterface $notificationService;
    private LoggerInterface $logger;
    private Database $db;
    public function __construct(
        XpService $xpService,
        ReferralService $referralService,
        NotificationServiceInterface $notificationService,
        LoggerInterface $logger,
        Database $db
    ) {        $this->xpService = $xpService;
        $this->referralService = $referralService;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
        $this->db = $db;
}

    public function handle(PaymentCompletedEvent $event): void
    {
        $userId = $event->userId;
        $amount = $event->amount;
        $currency = $event->currency;
        $transactionId = $event->transactionId;
        $gateway = $event->gateway;

        // ۱. اعطای XP بر اساس مبلغ پرداختی
        $this->awardXp($userId, $amount, $transactionId);

        // ۲. پردازش کمیسیون ریفرال
        $this->processReferralCommission($userId, $amount, $currency, $transactionId);

        // ۳. ارسال اعلان تبریک
        $this->sendDepositNotification($userId, $amount, $currency, $gateway, $transactionId);
    }

    private function awardXp(int $userId, float $amount, string $transactionId): void
    {
        try {
            $xp = min(
                (float) floor($amount / self::XP_PER_UNIT),
                self::MAX_XP_PER_PAYMENT
            );

            if ($xp <= 0.0) {
                return;
            }

            $this->xpService->award(
                $userId,
                ModuleContext::GLOBAL,
                $xp,
                "gateway_deposit_{$transactionId}"
            );

            $this->logger->info('payment.xp_awarded', [
                'user_id'        => $userId,
                'xp'             => $xp,
                'amount'         => $amount,
                'transaction_id' => $transactionId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('payment.xp_award_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function processReferralCommission(
        int $userId,
        float $amount,
        string $currency,
        string $transactionId
    ): void {
        try {
            $stmt = $this->db->prepare(
                "SELECT referred_by FROM users WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$userId]);
            $referrerId = $stmt->fetchColumn();

            if (!$referrerId) {
                return;
            }

            $this->referralService->processCommission(
                (int) $referrerId,
                (string) $amount,
                strtolower($currency),
                [
                    'action'         => 'deposit',
                    'executor_id'    => $userId,
                    'transaction_id' => $transactionId,
                ]
            );

            $this->logger->info('payment.referral_commission_processed', [
                'user_id'     => $userId,
                'referrer_id' => $referrerId,
                'amount'      => $amount,
                'currency'    => $currency,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('payment.referral_commission_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function sendDepositNotification(
        int $userId,
        float $amount,
        string $currency,
        string $gateway,
        string $transactionId
    ): void {
        try {
            $formattedAmount = number_format($amount, 0, '.', ',');
            $currencyLabel   = strtoupper($currency) === 'IRT' ? 'تومان' : strtoupper($currency);

            $this->notificationService->send(
                $userId,
                'deposit_success',
                'واریز موفق',
                "مبلغ {$formattedAmount} {$currencyLabel} با موفقیت به کیف پول شما اضافه شد.",
                [
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'gateway'        => $gateway,
                    'transaction_id' => $transactionId,
                ],
                null,
                null,
                'high'
            );
        } catch (\Throwable $e) {
            $this->logger->error('payment.deposit_notification_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
