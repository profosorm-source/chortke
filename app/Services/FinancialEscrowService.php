<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * FinancialEscrowService - Unified escrow management for all financial modules
 * 
 * Uses EscrowService as foundation + module-specific business logic
 * Modules: SocialTask (advertiser→executor), Influencer (buyer→seller), Vitrine (buyer→seller)
 */
class FinancialEscrowService extends \App\Services\BaseService
{
    private EscrowService $escrow;
    private User         $userModel;
    private WalletService $wallet;
    private Database     $db;

    public function __construct(
        EscrowService $escrow,
        User         $userModel,
        LoggerInterface       $logger,
        WalletService $wallet,
        Database $db
    ) {
        parent::__construct($logger);
        $this->escrow = $escrow;
        $this->userModel = $userModel;
        $this->wallet = $wallet;
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SocialTask Escrow (Advertiser → Executor Payment)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * درخواست نگهداری پول از تبلیغ‌دهنده برای اجرا
     * Flow: Executor submits → Escrow holds → Admin approves → Funds released
     */
    public function holdSocialTaskFunds(
        int    $executionId,
        int    $executorId,
        int    $advertiserId,
        float  $reward
    ): array {
        try {
            $this->db->beginTransaction();

            // ✅ Verify advertiser has sufficient balance
            $advertiserBalance = $this->userModel->getWalletBalance($advertiserId);

            if ($advertiserBalance < $reward) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Insufficient advertiser balance'];
            }

            // ✅ Create escrow via core service
            $result = $this->escrow->holdFunds(
                $executionId,
                'social_task_execution',
                $executorId,
                $advertiserId,
                $reward,
                'IRR'
            );

            if (!$result['ok']) {
                $this->db->rollBack();
                return $result;
            }

            // ✅ Deduct from advertiser wallet (lock funds)
            $this->wallet->withdraw($advertiserId, $reward, 'irt', [
                'type' => 'social_task_escrow',
                'execution_id' => $executionId
            ]);

            $this->db->commit();

            $this->logger->info('social_task.escrow_hold', [
                'execution_id' => $executionId,
                'executor_id' => $executorId,
                'adS_id' => $advertiserId,
                'amount' => $reward,
            ]);

            return ['ok' => true, 'escrow_id' => $result['escrow_id'] ?? null];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('social_task.escrow_hold.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تایید و نگهداری مالی برای SocialTask
     * Admin approves execution → Move to in_escrow
     */
    public function confirmSocialTaskEscrow(int $executionId, int $adviserId): array
    {
        return $this->escrow->confirmHold($executionId, 'social_task_execution', $adviserId);
    }

    /**
     * تحویل پول به executor
     * Admin releases → Transfer to executor wallet
     */
    public function releaseSocialTaskFunds(
        int    $executionId,
        int    $executorId,
        int    $advertiserId,
        float  $amount
    ): array {
        try {
            $this->db->beginTransaction();

            // ✅ Get escrow info
            $escrow = $this->escrow->getByOrder($executionId, 'social_task_execution');
            if (!$escrow || $escrow->status !== 'in_escrow') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow not in proper state'];
            }

            // ✅ Release via core escrow service
            $result = $this->escrow->releaseFunds($escrow->id, $advertiserId, 'admin_release');
            if (!$result['ok']) {
                $this->db->rollBack();
                return $result;
            }

            // ✅ Transfer to executor wallet
            $this->wallet->deposit($executorId, $amount, 'irt', [
                'type' => 'social_task_reward',
                'execution_id' => $executionId
            ]);

            $this->db->commit();

            $this->logger->info('social_task.escrow_released', [
                'execution_id' => $executionId,
                'executor_id' => $executorId,
                'amount' => $amount,
            ]);

            return ['ok' => true, 'wallet_transaction' => 'completed'];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('social_task.escrow_release.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * بازگرداندی پول به تبلیغ‌دهنده (رد شدن، dispute)
     */
    public function refundSocialTaskFunds(
        int    $executionId,
        int    $advertiserId,
        string $reason
    ): array {
        try {
            $this->db->beginTransaction();

            $escrow = $this->escrow->getByOrder($executionId, 'social_task_execution');
            if (!$escrow) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'No escrow found'];
            }

            // ✅ Refund via core service
            $result = $this->escrow->refundFunds(
                $escrow->id,
                $escrow->buyer_id,
                $reason,
                'admin_refund'
            );

            if (!$result['ok']) {
                $this->db->rollBack();
                return $result;
            }

            // ✅ Return to advertiser wallet
            $this->wallet->deposit(
                $advertiserId,
                $escrow->amount,
                'irt',
                [
                    'type' => 'social_task_refund',
                    'execution_id' => $executionId
                ]
            );

            $this->db->commit();

            $this->logger->info('social_task.escrow_refunded', [
                'execution_id' => $executionId,
                'amount' => $escrow->amount,
                'reason' => $reason,
            ]);

            return ['ok' => true, 'refund_amount' => $escrow->amount];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('social_task.escrow_refund.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Influencer Escrow (Buyer → Seller Payment)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * نگهداری پول برای سفارش اینفلوئنسر
     */
    public function holdInfluencerOrderFunds(
        int    $orderId,
        int    $buyerId,
        int    $sellerId,
        float  $amount
    ): array {
        try {
            $this->db->beginTransaction();

            // ✅ Verify buyer balance
            $buyerBalance = $this->userModel->getWalletBalance($buyerId);

            if ($buyerBalance < $amount) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Insufficient buyer balance'];
            }

            // ✅ Hold in escrow
            $result = $this->escrow->holdFunds(
                $orderId,
                'influencer_order',
                $buyerId,
                $sellerId,
                $amount,
                'IRR'
            );

            if (!$result['ok']) {
                $this->db->rollBack();
                return $result;
            }

            // ✅ Deduct from buyer wallet
            $this->wallet->withdraw($buyerId, $amount, 'irt', [
                'type' => 'influencer_escrow',
                'order_id' => $orderId
            ]);

            $this->db->commit();

            return ['ok' => true, 'escrow_id' => $result['escrow_id'] ?? null];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تحویل پول به فروشنده (اینفلوئنسر)
     */
    public function releaseInfluencerOrderFunds(int $orderId, int $sellerId, float $amount): array
    {
        try {
            $this->db->beginTransaction();

            $escrow = $this->escrow->getByOrder($orderId, 'influencer_order');
            if (!$escrow || $escrow->status !== 'in_escrow') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Invalid escrow state'];
            }

            // ✅ Release & transfer
            $result = $this->escrow->releaseFunds($escrow->id, $sellerId, 'order_complete');
            if (!$result['ok']) {
                $this->db->rollBack();
                return $result;
            }

            $this->wallet->deposit($sellerId, $amount, 'irt', [
                'type' => 'influencer_order_payment',
                'order_id' => $orderId
            ]);

            $this->db->commit();

            return ['ok' => true];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Vitrine Escrow (Buyer → Seller Payment)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * نگهداری پول برای آگهی ویترین
     */
    public function holdVitrineFunds(
        int    $listingId,
        int    $buyerId,
        int    $sellerId,
        float  $amount
    ): array {
        try {
            $this->db->beginTransaction();

            // ✅ Verify buyer
            $buyerBalance = $this->userModel->getWalletBalance($buyerId);

            if ($buyerBalance < $amount) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Insufficient balance'];
            }

            // ✅ Hold escrow
            $result = $this->escrow->holdFunds(
                $listingId,
                'vitrine_listing',
                $buyerId,
                $sellerId,
                $amount,
                'USDT'
            );

            if (!$result['ok']) {
                return $result;
            }

            // ✅ Deduct from buyer
            $this->wallet->withdraw($buyerId, $amount, 'usdt', [
                'type' => 'vitrine_escrow',
                'listing_id' => $listingId
            ]);

            $this->db->commit();
            return ['ok' => true, 'escrow_id' => $result['escrow_id'] ?? null];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تحویل پول به فروشنده (ویترین)
     */
    public function releaseVitrineFunds(int $listingId, int $sellerId, float $amount): array
    {
        try {
            $this->db->beginTransaction();

            $escrow = $this->escrow->getByOrder($listingId, 'vitrine_listing');
            if (!$escrow || $escrow->status !== 'in_escrow') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Invalid escrow state'];
            }

            // ✅ Release
            $result = $this->escrow->releaseFunds($escrow->id, $sellerId, 'vitrine_sale_complete');
            if (!$result['ok']) {
                $this->db->rollBack();
                return $result;
            }

            // ✅ Calculate commission & transfer net
            $commission = $amount * 0.05; // 5% commission
            $netAmount = $amount - $commission;

            $this->wallet->deposit($sellerId, $netAmount, 'usdt', [
                'type' => 'vitrine_sale',
                'listing_id' => $listingId
            ]);

            $this->db->commit();
            return ['ok' => true, 'net_amount' => $netAmount, 'commission' => $commission];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * بازگرداندی پول به خریدار (ویترین)
     */
    public function refundVitrineFunds(
        int    $listingId,
        int    $buyerId,
        string $reason
    ): array {
        try {
            $this->db->beginTransaction();

            $escrow = $this->escrow->getByOrder($listingId, 'vitrine_listing');
            if (!$escrow) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'No escrow found'];
            }

            // ✅ Refund
            $result = $this->escrow->refundFunds(
                $escrow->id,
                $buyerId,
                $reason,
                'vitrine_refund'
            );

            if (!$result['ok']) {
                $this->db->rollBack();
                return $result;
            }

            $this->wallet->deposit($buyerId, $escrow->amount, 'usdt', [
                'type' => 'vitrine_refund',
                'listing_id' => $listingId
            ]);

            $this->db->commit();
            return ['ok' => true, 'refund_amount' => $escrow->amount];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Common Dispute Handling
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Mark escrow as disputed (freezes funds)
     */
    public function markEscrowDisputed(int $orderId, string $orderType, string $reason): array
    {
        $escrow = $this->escrow->getByOrder($orderId, $orderType);
        if (!$escrow) {
            return ['ok' => false, 'error' => 'Escrow not found'];
        }

        return $this->escrow->markAsDisputed($escrow->id, $reason);
    }

    /**
     * Resolve dispute and release/refund based on verdict
     */
    public function resolveDisputedEscrow(
        int    $orderId,
        string $orderType,
        string $verdict,
        float  $refundPercent
    ): array {
        try {
            $this->db->beginTransaction();

            $escrow = $this->escrow->getByOrder($orderId, $orderType);
            if (!$escrow || $escrow->status !== 'disputed') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Not in disputed state'];
            }

            $refundAmount = $escrow->amount * ($refundPercent / 100);
            $releaseAmount = $escrow->amount - $refundAmount;

            if ($verdict === 'favor_seller') {
                // Release all to seller
                $result = $this->escrow->releaseFunds(
                    $escrow->id,
                    $escrow->seller_id,
                    'dispute_resolved_favor_seller'
                );
                if ($result['ok']) {
                    $this->wallet->deposit(
                        $escrow->seller_id,
                        $releaseAmount,
                        $escrow->currency === 'USDT' ? 'usdt' : 'irt',
                        [
                            'type' => 'dispute_release',
                            'order_id' => $orderId
                        ]
                    );
                }
            } else {
                // Partial release + refund
                $result = $this->escrow->refundFunds(
                    $escrow->id,
                    $escrow->buyer_id,
                    "dispute_resolved_partial_favor_buyer ($refundPercent%)",
                    'dispute_resolution'
                );
                if ($result['ok']) {
                    $currency = $escrow->currency === 'USDT' ? 'usdt' : 'irt';
                    $this->wallet->deposit($escrow->buyer_id, $refundAmount, $currency, [
                        'type' => 'dispute_refund',
                        'order_id' => $orderId
                    ]);
                    if ($releaseAmount > 0) {
                        $this->wallet->deposit(
                            $escrow->seller_id,
                            $releaseAmount,
                            $currency,
                            [
                                'type' => 'dispute_partial_release',
                                'order_id' => $orderId
                            ]
                        );
                    }
                }
            }

            $this->db->commit();
            return ['ok' => true, 'released' => $releaseAmount, 'refunded' => $refundAmount];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
