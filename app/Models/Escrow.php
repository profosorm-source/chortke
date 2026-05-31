<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class Escrow extends Model
{
    protected static string $table = 'escrow_transactions';

    public function findByOrderId(int $orderId, string $orderType, string $excludeStatus = 'refunded'): ?object
    {
        $stmt = $this->db->query(
            "SELECT id FROM escrow_transactions 
             WHERE order_id = ? AND order_type = ? AND status != ? 
             LIMIT 1",
            [$orderId, $orderType, $excludeStatus]
        );

        return $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
    }

    public function createEscrow(
        int $orderId,
        string $orderType,
        int $buyerId,
        int $sellerId,
        string $amount,
        string $currency = 'USDT'
    ): int|false {
        $stmt = $this->db->prepare(
            "INSERT INTO escrow_transactions 
             (order_id, order_type, buyer_id, seller_id, amount, currency, status, held_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $result = $stmt->execute([
            $orderId,
            $orderType,
            $buyerId,
            $sellerId,
            $amount,
            $currency,
            'pending',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        return $result ? $this->db->lastInsertId() : false;
    }

    public function findPendingForConfirm(int $orderId, string $orderType, int $sellerId): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE order_id = ? AND order_type = ? AND seller_id = ?
             AND status = 'pending' FOR UPDATE",
            [$orderId, $orderType, $sellerId]
        );

        return $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
    }

    public function confirmHold(int $escrowId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE escrow_transactions 
             SET status = 'in_escrow', confirmed_at = ?
             WHERE id = ? AND status = 'pending'"
        );

        $result = $stmt->execute([date('Y-m-d H:i:s'), $escrowId]);
        return $result && $stmt->rowCount() > 0;
    }

    public function confirmHoldWithTransaction(int $orderId, string $orderType, int $sellerId): bool
    {
        try {
            $this->db->beginTransaction();

            $escrow = $this->findPendingForConfirm($orderId, $orderType, $sellerId);
            if (!$escrow) {
                $this->db->rollBack();
                return false;
            }

            $success = $this->confirmHold((int)$escrow->id);
            if ($success) {
                $this->logEscrowAction((int)$escrow->id, 'confirm_hold', (string)$escrow->amount, 'seller_' . $sellerId, 'Held funds confirmed');
                $this->db->commit();
                return true;
            }

            $this->db->rollBack();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    public function findReleasable(int $escrowId, int $sellerId): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE id = ? AND seller_id = ? 
             AND status IN ('in_escrow', 'partial')
             FOR UPDATE",
            [$escrowId, $sellerId]
        );

        return $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
    }

    public function releaseFunds(int $escrowId, string $releasedBy): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE escrow_transactions 
             SET status = 'released', released_at = ?, released_by = ?
             WHERE id = ? AND status IN ('in_escrow', 'partial')"
        );

        $result = $stmt->execute([date('Y-m-d H:i:s'), $releasedBy, $escrowId]);
        return $result && $stmt->rowCount() > 0;
    }

    public function releaseFundsWithTransaction(int $escrowId, int $sellerId, string $releasedBy): bool
    {
        try {
            $this->db->beginTransaction();

            $escrow = $this->findReleasable($escrowId, $sellerId);
            if (!$escrow) {
                $this->db->rollBack();
                return false;
            }

            $success = $this->releaseFunds($escrowId, $releasedBy);
            if ($success) {
                $this->logEscrowAction($escrowId, 'release_funds', (string)$escrow->amount, $releasedBy, 'Escrow funds released');
                $this->db->commit();
                return true;
            }

            $this->db->rollBack();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    public function logEscrowAction(int $escrowId, string $action, string $amount, string $performedBy, ?string $note = null): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO escrow_audit 
             (escrow_id, action, amount, performed_by, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $result = $stmt->execute([
            $escrowId,
            $action,
            $amount,
            $performedBy,
            $note,
            date('Y-m-d H:i:s')
        ]);

        if (!$result) {
            throw new \RuntimeException("Failed to log escrow action: {$action} for escrow {$escrowId}");
        }

        return true;
    }

    public function findRefundable(int $escrowId, int $buyerId): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE id = ? AND buyer_id = ? 
             AND status IN ('in_escrow', 'pending', 'disputed')
             FOR UPDATE",
            [$escrowId, $buyerId]
        );

        return $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
    }

    public function refundFunds(int $escrowId, string $reason, string $refundedBy): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE escrow_transactions 
             SET status = 'refunded', 
                 refunded_at = ?, 
                 refund_reason = ?,
                 refunded_by = ?
             WHERE id = ? AND status IN ('in_escrow', 'pending', 'disputed')"
        );

        $result = $stmt->execute([
            date('Y-m-d H:i:s'),
            $reason,
            $refundedBy,
            $escrowId
        ]);

        return $result && $stmt->rowCount() > 0;
    }

    public function refundFundsWithTransaction(int $escrowId, int $buyerId, string $reason, string $refundedBy): bool
    {
        try {
            $this->db->beginTransaction();

            $escrow = $this->findRefundable($escrowId, $buyerId);
            if (!$escrow) {
                $this->db->rollBack();
                return false;
            }

            $success = $this->refundFunds($escrowId, $reason, $refundedBy);
            if ($success) {
                $this->logEscrowAction($escrowId, 'refund_funds', (string)$escrow->amount, $refundedBy, 'Escrow funds refunded: ' . $reason);
                $this->db->commit();
                return true;
            }

            $this->db->rollBack();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    public function markDisputed(int $escrowId, string $reason): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE escrow_transactions 
             SET status = 'disputed', disputed_at = ?, dispute_reason = ?
             WHERE id = ? AND status IN ('in_escrow', 'pending')"
        );

        $result = $stmt->execute([date('Y-m-d H:i:s'), $reason, $escrowId]);
        return $result && $stmt->rowCount() > 0;
    }

    public function getStatus(int $escrowId): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions WHERE id = ?",
            [$escrowId]
        );

        return $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
    }

    public function getByOrder(int $orderId, string $orderType): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE order_id = ? AND order_type = ? 
             ORDER BY id DESC LIMIT 1",
            [$orderId, $orderType]
        );

        return $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
    }

    public function isExpired(int $escrowId): bool
    {
        $stmt = $this->db->query(
            "SELECT expires_at FROM escrow_transactions WHERE id = ?",
            [$escrowId]
        );

        $escrow = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $escrow && strtotime($escrow->expires_at) < time();
    }
}
