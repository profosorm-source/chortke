<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Escrow;
use Core\Database;
use App\Contracts\LoggerInterface;
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
    public function __construct(Escrow $escrowModel, Database $db, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->escrowModel = $escrowModel;
        $this->db          = $db;
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
        float  $amount,
        string $currency = 'USDT'
    ): array {
        try {
            $this->db->beginTransaction();

            // ✅ Check if escrow already exists
            $existing = $this->escrowModel->findByOrderId($orderId, $orderType, 'refunded');

            if ($existing) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow already exists for this order'];
            }

            // ✅ Validate amount
            if ($amount <= 0) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Invalid amount'];
            }

            // ✅ Create escrow record
            $escrowData = [
                'order_id'    => $orderId,
                'order_type'  => $orderType,
                'buyer_id'    => $buyerId,
                'seller_id'   => $sellerId,
                'amount'      => $amount,
                'currency'    => $currency,
                'status'      => 'pending', // awaiting transfer
                'held_at'     => date('Y-m-d H:i:s'),
                'expires_at'  => date('Y-m-d H:i:s', strtotime('+30 days')),
            ];

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

            $this->db->commit();
            return ['ok' => true, 'escrow_id' => $escrowId];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('escrow.hold.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تایید و نگهداری funds (pending → in_escrow)
     * ✅ With database locking
     */
    public function confirmHold(int $orderId, string $orderType, int $sellerId): array
    {
        try {
            $this->db->beginTransaction();

            // ✅ Acquire write lock
            $escrow = $this->escrowModel->findPendingForConfirm($orderId, $orderType, $sellerId);

            if (!$escrow) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow not found or already confirmed'];
            }

            // ✅ Update status
            $result = $this->escrowModel->confirmHold($escrow->id);

            if (!$result) {
                throw new \Exception('Failed to confirm escrow');
            }

            $this->logger->info('escrow.confirmed', [
                'escrow_id' => $escrow->id,
                'order_id' => $orderId,
                'amount' => $escrow->amount,
            ]);

            $this->db->commit();
            return ['ok' => true, 'escrow_id' => $escrow->id];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('escrow.confirm.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تحویل funds به فروشنده (in_escrow → released)
     * ✅ Final state - cannot be reversed
     */
    public function releaseFunds(int $escrowId, int $sellerId, string $releasedBy): array
    {
        try {
            $this->db->beginTransaction();

            // ✅ Acquire lock & validate state
            $escrow = $this->escrowModel->findReleasable($escrowId, $sellerId);

            if (!$escrow) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow not found or cannot be released'];
            }

            // ✅ Update escrow status
            $result = $this->escrowModel->releaseFunds($escrowId, $releasedBy);

            if (!$result) {
                throw new \Exception('Failed to release funds');
            }

            // ✅ Log audit trail
            $this->escrowModel->logEscrowAction($escrowId, 'released', $escrow->amount, $releasedBy);

            $this->logger->info('escrow.released', [
                'escrow_id' => $escrowId,
                'order_id' => $escrow->order_id,
                'amount' => $escrow->amount,
                'seller_id' => $sellerId,
            ]);

            $this->db->commit();
            return ['ok' => true, 'amount' => $escrow->amount];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('escrow.release.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
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
        try {
            $this->db->beginTransaction();

            // ✅ Acquire lock
            $escrow = $this->escrowModel->findRefundable($escrowId, $buyerId);

            if (!$escrow) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Escrow not found or cannot be refunded'];
            }

            // ✅ Prevent double refund
            if ($escrow->status === 'refunded') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Already refunded'];
            }

            // ✅ Update status
            $result = $this->escrowModel->refundFunds($escrowId, $reason, $initiatedBy);

            if (!$result) {
                throw new \Exception('Failed to refund');
            }

            // ✅ Log refund
            $this->escrowModel->logEscrowAction($escrowId, 'refunded', $escrow->amount, $initiatedBy, $reason);

            $this->logger->info('escrow.refunded', [
                'escrow_id' => $escrowId,
                'order_id' => $escrow->order_id,
                'amount' => $escrow->amount,
                'reason' => $reason,
            ]);

            $this->db->commit();
            return ['ok' => true, 'amount' => $escrow->amount, 'refund_id' => $escrowId];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('escrow.refund.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * وضعیت را به disputed تغییر بده (در صورت اختلاف)
     * ✅ Prevents release/refund during dispute
     */
    public function markAsDisputed(int $escrowId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $result = $this->escrowModel->markDisputed($escrowId, $reason);

            if (!$result) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Failed to mark as disputed'];
            }

            $this->logger->info('escrow.disputed', ['escrow_id' => $escrowId, 'reason' => $reason]);
            $this->db->commit();
            return ['ok' => true];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
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

