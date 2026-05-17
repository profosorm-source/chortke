<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Escrow;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\LedgerService;

/**
 * EscrowService - تسویه‌ مرکزی برای تمام ماژول‌های مالی
 * 
 * وضعیت‌های Escrow:
 * - pending:    انتقال از seller/advertiser منتظر
 * - in_escrow:  funds held
 * - released:   transferred to seller/advertiser
 * - refunded:   returned to buyer
 * - disputed:   waiting for resolution
 */
class EscrowService extends \App\Services\BaseService
{
    private Escrow   $escrowModel;
    private Database $db;
    private LedgerService $ledgerService;

    public function __construct(
        Escrow $escrowModel,
        Database $db,
        LoggerInterface $logger,
        LedgerService $ledgerService
    ) {
        parent::__construct($logger);
        $this->escrowModel = $escrowModel;
        $this->db          = $db;
        $this->ledgerService = $ledgerService;
    }

    /**
     * درخواست نگهداری funds (Seller → Escrow)
     * ✅ Transaction-based state machine
     */
    public function holdFunds(
        int    $orderId,
        string $orderType,
        int    $buyerId,
        int    $sellerId,
        string $amount,
        string $currency = 'USDT'
    ): array {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('holdFunds must be called inside an active transaction');
        }

        // ✅ Check if escrow already exists
        $existing = $this->escrowModel->findByOrderId($orderId, $orderType, 'refunded');

        if ($existing) {
            return ['ok' => false, 'error' => 'Escrow already exists for this order'];
        }

        // ✅ Validate amount
        if (bccomp($amount, '0', 8) <= 0) {
            return ['ok' => false, 'error' => 'Invalid amount'];
        }

        $escrowId = $this->escrowModel->createEscrow(
            $orderId,
            $orderType,
            $buyerId,
            $sellerId,
            $amount,
            $currency
        );

        if (!$escrowId) {
            throw new \Exception('Failed to create escrow record');
        }

        $this->logger->info('escrow.hold_requested', [
            'order_id' => $orderId,
            'order_type' => $orderType,
            'amount' => $amount,
            'buyer_id' => $buyerId,
            'seller_id' => $sellerId,
        ]);

        return ['ok' => true, 'escrow_id' => (int)$escrowId];
    }

    /**
     * تایید و نگهداری funds (pending → in_escrow)
     * ✅ With database locking
     */
    public function confirmHold(int $orderId, string $orderType, int $sellerId): array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('confirmHold must be called inside an active transaction');
        }

        // ✅ Acquire write lock
        $escrow = $this->escrowModel->findPendingForConfirm($orderId, $orderType, $sellerId);

        if (!$escrow) {
            return ['ok' => false, 'error' => 'Escrow not found or already confirmed'];
        }

        // ✅ Update status
        $result = $this->escrowModel->confirmHold((int)$escrow->id);

        if (!$result) {
            throw new \Exception('Failed to confirm escrow');
        }

        $this->logger->info('escrow.confirmed', [
            'escrow_id' => $escrow->id,
            'order_id' => $orderId,
            'amount' => $escrow->amount,
        ]);

        return ['ok' => true, 'escrow_id' => (int)$escrow->id];
    }

    /**
     * تحویل funds به فروشنده (in_escrow → released)
     * ✅ Final state - cannot be reversed
     */
    /**
     * ⚠️ NOTE: This method only updates the escrow state and performs ledger records.
     * The caller is responsible for depositing the released funds into the seller's wallet.
     */
    public function releaseFunds(int $escrowId, int $sellerId, string $releasedBy): array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('releaseFunds must be called inside an active transaction');
        }

        // ✅ Acquire lock & validate state
        $escrow = $this->escrowModel->findReleasable($escrowId, $sellerId);

        if (!$escrow) {
            return ['ok' => false, 'error' => 'Escrow not found or cannot be released'];
        }

        // ✅ Update escrow status
        $result = $this->escrowModel->releaseFunds($escrowId, $releasedBy);

        if (!$result) {
            throw new \Exception('Failed to release funds');
        }

        // ✅ Log audit trail
        $this->escrowModel->logEscrowAction($escrowId, 'released', $escrow->amount, $releasedBy);

        // ✅ BUG-03 Fix: Record double-entry bookkeeping ledger records for auditing
        $this->ledgerService->recordDoubleEntry(
            "escrow_release_{$escrowId}",
            "escrow:{$escrowId}",          // debit from escrow
            "wallet:user:{$sellerId}",      // credit to seller
            $escrow->amount,
            strtolower($escrow->currency),
            "Escrow release for order {$escrow->order_id}",
            ['escrow_id' => $escrowId, 'released_by' => $releasedBy]
        );

        $this->logger->info('escrow.released', [
            'escrow_id' => $escrowId,
            'order_id' => $escrow->order_id,
            'amount' => $escrow->amount,
            'seller_id' => $sellerId,
        ]);

        return ['ok' => true, 'amount' => $escrow->amount];
    }

    /**
     * بازگرداندن funds به خریدار (in_escrow/pending → refunded)
     * ✅ Used for cancellations or refunds
     */
    public function refundFunds(
        int    $escrowId,
        int    $buyerId,
        string $reason,
        string $initiatedBy
    ): array {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('refundFunds must be called inside an active transaction');
        }

        // ✅ Acquire lock
        $escrow = $this->escrowModel->findRefundable($escrowId, $buyerId);

        if (!$escrow) {
            return ['ok' => false, 'error' => 'Escrow not found or cannot be refunded'];
        }

        // ✅ Prevent double refund
        if ($escrow->status === 'refunded') {
            return ['ok' => false, 'error' => 'Already refunded'];
        }

        // ✅ Update status
        $result = $this->escrowModel->refundFunds($escrowId, $reason, $initiatedBy);

        if (!$result) {
            throw new \Exception('Failed to refund');
        }

        // ✅ Log refund
        $this->escrowModel->logEscrowAction($escrowId, 'refunded', $escrow->amount, $initiatedBy, $reason);

        // ✅ BUG-04 Fix: Record double-entry bookkeeping ledger records for auditing refunds
        $this->ledgerService->recordDoubleEntry(
            "escrow_refund_{$escrowId}",
            "escrow:{$escrowId}",          // debit from escrow
            "wallet:user:{$escrow->buyer_id}",      // credit to buyer
            $escrow->amount,
            strtolower($escrow->currency),
            "Escrow refund for order {$escrow->order_id}: {$reason}",
            ['escrow_id' => $escrowId, 'initiated_by' => $initiatedBy, 'reason' => $reason]
        );

        $this->logger->info('escrow.refunded', [
            'escrow_id' => $escrowId,
            'order_id' => $escrow->order_id,
            'amount' => $escrow->amount,
            'reason' => $reason,
        ]);

        return ['ok' => true, 'amount' => $escrow->amount, 'refund_id' => $escrowId];
    }

    /**
     * وضعیت را به disputed تغییر بده (در صورت اختلاف)
     * ✅ Prevents release/refund during dispute
     */
    public function markAsDisputed(int $escrowId, string $reason): array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('markAsDisputed must be called inside an active transaction');
        }

        $result = $this->escrowModel->markDisputed($escrowId, $reason);

        if (!$result) {
            return ['ok' => false, 'error' => 'Failed to mark as disputed'];
        }

        $this->logger->info('escrow.disputed', ['escrow_id' => $escrowId, 'reason' => $reason]);
        return ['ok' => true];
    }

    /**
     * حل اختلاف و تقسیم وجه امانی به صورت جزئی یا کلی (BUG-05)
     */
    public function resolveDisputePartial(
        int    $escrowId,
        int    $buyerId,
        int    $sellerId,
        string $refundAmount,
        string $releaseAmount,
        string $initiatedBy,
        string $verdict
    ): array {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('resolveDisputePartial must be called inside an active transaction');
        }

        // Lock & get escrow
        $escrow = $this->escrowModel->findRefundable($escrowId, $buyerId);
        if (!$escrow) {
            return ['ok' => false, 'error' => 'Escrow not found or not in refundable/disputed state'];
        }

        // Update status to released or refunded based on verdict
        $status = $verdict === 'favor_seller' ? 'released' : (($releaseAmount === '0' || bccomp($releaseAmount, '0', 8) === 0) ? 'refunded' : 'released');

        $stmt = $this->db->prepare("UPDATE escrow_transactions SET status = ?, released_at = ?, released_by = ? WHERE id = ?");
        $stmt->execute([$status, date('Y-m-d H:i:s'), $initiatedBy, $escrowId]);

        // Log actions and record proper double-entry ledger records
        if (bccomp($refundAmount, '0', 8) > 0) {
            $this->escrowModel->logEscrowAction($escrowId, 'dispute_refunded', $refundAmount, $initiatedBy, "Dispute resolved with partial refund");
            $this->ledgerService->recordDoubleEntry(
                "escrow_refund_dispute_{$escrowId}",
                "escrow:{$escrowId}",
                "wallet:user:{$buyerId}",
                $refundAmount,
                strtolower($escrow->currency),
                "Escrow dispute partial refund for order {$escrow->order_id}",
                ['escrow_id' => $escrowId, 'initiated_by' => $initiatedBy]
            );
        }

        if (bccomp($releaseAmount, '0', 8) > 0) {
            $this->escrowModel->logEscrowAction($escrowId, 'dispute_released', $releaseAmount, $initiatedBy, "Dispute resolved with partial release");
            $this->ledgerService->recordDoubleEntry(
                "escrow_release_dispute_{$escrowId}",
                "escrow:{$escrowId}",
                "wallet:user:{$sellerId}",
                $releaseAmount,
                strtolower($escrow->currency),
                "Escrow dispute partial release for order {$escrow->order_id}",
                ['escrow_id' => $escrowId, 'released_by' => $initiatedBy]
            );
        }

        return ['ok' => true];
    }

    /**
     * دریافت وضعیت escrow
     */
    public function getStatus(int $escrowId): ?object
    {
        return $this->escrowModel->getStatus($escrowId);
    }

    /**
     * دریافت escrow برای order
     */
    public function getByOrder(int $orderId, string $orderType): ?object
    {
        return $this->escrowModel->getByOrder($orderId, $orderType);
    }

    /**
     * بررسی اینکه آیا escrow منقضی‌ شده (مثل قبل از تحویل)
     */
    public function isExpired(int $escrowId): bool
    {
        return $this->escrowModel->isExpired($escrowId);
    }
}
