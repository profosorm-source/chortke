<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * InfluencerOrderTimeoutJob
 *
 * بستن خودکار سفارش‌های اینفلوئنسر که منقضی شده‌اند:
 * - سفارش‌هایی که اینفلوئنسر در مهلت مقرر content تحویل نداده → refund به buyer
 * - سفارش‌هایی که buyer در مهلت مقرر پاسخ به review نداده → auto-approve → پرداخت به influencer
 */
class InfluencerOrderTimeoutJob
{
    // ساعت تا پرداخت خودکار به اینفلوئنسر پس از اتمام مهلت بررسی buyer
    private const BUYER_REVIEW_AUTO_APPROVE_HOURS = 48;

    public function __construct(
        private Database $db,
        private WalletServiceInterface $walletService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger
    ) {}

    public function handle(array $data = []): void
    {
        $this->cancelExpiredOrders();
        $this->autoApproveBuyerTimeout();
    }

    /**
     * لغو سفارش‌هایی که اینفلوئنسر در مهلت پاسخ نداده است
     */
    private function cancelExpiredOrders(): void
    {
        try {
            $expired = $this->db->fetchAll(
                "SELECT o.*, u_buyer.id as buyer_id
                 FROM influencer_orders o
                 JOIN users u_buyer ON u_buyer.id = o.buyer_id
                 WHERE o.status = 'pending_acceptance'
                   AND o.deadline < NOW()
                 LIMIT 100"
            );

            foreach ($expired as $order) {
                try {
                    $this->db->beginTransaction();

                    $this->db->prepare(
                        "UPDATE influencer_orders SET status = 'cancelled', cancelled_at = NOW(),
                         cancellation_reason = 'influencer_timeout' WHERE id = ? AND status = 'pending_acceptance'"
                    )->execute([$order->id]);

                    // بازگرداندن مبلغ به buyer
                    $this->walletService->deposit(
                        (int) $order->buyer_id,
                        (string) $order->amount,
                        'irt',
                        ['type' => 'influencer_order_refund', 'order_id' => $order->id, 'reason' => 'influencer_timeout']
                    );

                    $this->db->commit();

                    $this->notificationService->send(
                        (int) $order->buyer_id,
                        'influencer_order_cancelled',
                        'سفارش لغو شد',
                        'سفارش اینفلوئنسر شما به دلیل عدم پاسخ در مهلت مقرر لغو و مبلغ بازگردانده شد.',
                        ['order_id' => $order->id],
                        null,
                        null,
                        'high'
                    );
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->logger->error('influencer.order_timeout_cancel_failed', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('influencer.orders_cancelled_timeout', ['count' => count($expired)]);
        } catch (\Throwable $e) {
            $this->logger->error('influencer.order_timeout_job_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Auto-approve سفارش‌هایی که buyer در مهلت review پاسخ نداده → پرداخت به اینفلوئنسر
     */
    private function autoApproveBuyerTimeout(): void
    {
        try {
            $pending = $this->db->fetchAll(
                "SELECT o.*, u_influencer.id as influencer_id
                 FROM influencer_orders o
                 JOIN users u_influencer ON u_influencer.id = o.influencer_id
                 WHERE o.status = 'pending_buyer_review'
                   AND o.content_submitted_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
                 LIMIT 100",
                [self::BUYER_REVIEW_AUTO_APPROVE_HOURS]
            );

            foreach ($pending as $order) {
                try {
                    $this->db->beginTransaction();

                    $this->db->prepare(
                        "UPDATE influencer_orders SET status = 'completed', completed_at = NOW(),
                         auto_approved = 1 WHERE id = ? AND status = 'pending_buyer_review'"
                    )->execute([$order->id]);

                    // پرداخت به اینفلوئنسر
                    $this->walletService->deposit(
                        (int) $order->influencer_id,
                        (string) $order->influencer_earnings,
                        'irt',
                        ['type' => 'influencer_payout', 'order_id' => $order->id, 'reason' => 'buyer_review_timeout']
                    );

                    $this->db->commit();

                    $this->notificationService->send(
                        (int) $order->influencer_id,
                        'influencer_payment_released',
                        '💰 پرداخت منتشر شد',
                        'مبلغ سفارش به دلیل اتمام مهلت بررسی خریدار، به حساب شما واریز شد.',
                        ['order_id' => $order->id],
                        null,
                        null,
                        'high'
                    );
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->logger->error('influencer.auto_approve_failed', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('influencer.orders_auto_approved', ['count' => count($pending)]);
        } catch (\Throwable $e) {
            $this->logger->error('influencer.auto_approve_job_failed', ['error' => $e->getMessage()]);
        }
    }
}
