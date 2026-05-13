<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\Escrow;
use App\Models\LedgerEntry;
use Core\Database;
use App\Contracts\LoggerInterface;
/**
 * FinancialService — سرویس اشتراکی مالی داخلی (Escrow + Ledger)
 *
 * مسئولیت این سرویس:
 * - مدیریت وجه امانی (Escrow): نگهداری، آزادسازی، استرداد و علامت‌گذاری اختلاف
 * - ثبت دفتر کل (Ledger): ثبت تمام رویدادهای مالی داخلی برای حسابرسی
 */
class FinancialService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        protected LoggerInterface $logger,
        private Escrow $escrowModel,
        private LedgerEntry $ledgerModel
    ) {
        parent::__construct($logger);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Escrow Operations
    // ═══════════════════════════════════════════════════════════════════════

    public function createEscrow(int $orderId, string $orderType, int $buyerId, int $sellerId, string $amount, string $currency = 'USDT'): int|false
    {
        return $this->escrowModel->createEscrow($orderId, $orderType, $buyerId, $sellerId, $amount, $currency);
    }

    public function confirmEscrow(int $escrowId): bool
    {
        return $this->escrowModel->confirmHold($escrowId);
    }

    public function releaseEscrow(int $escrowId, string $releasedBy): bool
    {
        $result = $this->escrowModel->releaseFunds($escrowId, $releasedBy);
        if ($result) {
            $this->escrowModel->logEscrowAction($escrowId, 'released', 0, $releasedBy);
        }
        return $result;
    }

    public function refundEscrow(int $escrowId, int $buyerId, string $reason, string $refundedBy): bool
    {
        $escrow = $this->escrowModel->findRefundable($escrowId, $buyerId);
        if (!$escrow) return false;

        $result = $this->escrowModel->refundFunds($escrowId, $reason, $refundedBy);
        if ($result) {
            $this->escrowModel->logEscrowAction($escrowId, 'refunded', $escrow->amount, $refundedBy, $reason);
        }
        return $result;
    }

    public function disputeEscrow(int $escrowId, string $reason): bool
    {
        return $this->escrowModel->markDisputed($escrowId, $reason);
    }

    public function getEscrowStatus(int $escrowId): ?object
    {
        return $this->escrowModel->getStatus($escrowId);
    }

    public function findEscrowByOrder(int $orderId, string $orderType): ?object
    {
        return $this->escrowModel->getByOrder($orderId, $orderType);
    }

    public function isEscrowExpired(int $escrowId): bool
    {
        return $this->escrowModel->isExpired($escrowId);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Ledger Operations
    // ═══════════════════════════════════════════════════════════════════════

    public function logTransaction(
        string $transactionId,
        string $account,
        string $debit,
        string $credit,
        string $currency,
        ?string $description = null,
        array $metadata = []
    ): ?object {
        return $this->ledgerModel->create([
            'transaction_id' => $transactionId,
            'account' => $account,
            'debit' => $debit,
            'credit' => $credit,
            'currency' => $currency,
            'description' => $description,
            'metadata' => $metadata
        ]);
    }

    public function getTransactionLedger(string $transactionId): array
    {
        return $this->ledgerModel->getByTransactionId($transactionId);
    }
}

