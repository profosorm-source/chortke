<?php

declare(strict_types=1);

namespace App\Domain\Financial\Services;

use App\Contracts\WalletServiceInterface;
use App\Models\User;
use App\Contracts\LoggerInterface;
use App\Services\EscrowService;
use App\Services\Settings\AppSettings;
use App\Services\SagaOrchestrator;
use Core\Database;
use Core\IdempotencyKey;
use Core\ValueObjects\Money;

/**
 * FinancialEscrowService - Unified escrow management for all financial modules
 * 
 * Uses EscrowService as foundation + module-specific business logic
 * Modules: SocialTask (advertiser→executor), Influencer (buyer→seller), Vitrine (buyer→seller)
 */
class FinancialEscrowService
{
    private EscrowService $escrow;
    private User $userModel;
    private WalletServiceInterface $wallet;
    protected Database $db;
    private LoggerInterface $logger;
    private AppSettings $appSettings;
    private SagaOrchestrator $saga;
    private ?IdempotencyKey $idempotencyKey;

    public function __construct(
        EscrowService $escrow,
        User $userModel,
        WalletServiceInterface $wallet,
        AppSettings $appSettings,
        SagaOrchestrator $saga,
        Database $db,
        LoggerInterface $logger,
        ?IdempotencyKey $idempotencyKey = null
    ) {
        $this->escrow = $escrow;
        $this->userModel = $userModel;
        $this->wallet = $wallet;
        $this->appSettings = $appSettings;
        $this->saga = $saga;
        $this->db = $db;
        $this->logger = $logger;
        $this->idempotencyKey = $idempotencyKey;
    }

    private function executeWithIdempotency(?string $key, int $userId, string $action, callable $logic, ?array $requestData = null): array
    {
        if ($key && $this->idempotencyKey) {
            // Delegate to core wrapper which handles check/complete/fail atomically and logs
            return (array)$this->idempotencyKey->wrapInstance($key, $userId, $action, $logic, $requestData);
        }

        // Fallback when idempotency service isn't available
        try {
            $result = $logic();
            return is_array($result) ? $result : ['ok' => (bool)$result];
        } catch (\Throwable $e) {
            $this->logger->error('financial_escrow.operation_failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * درخواست نگهداری پول از تبلیغ‌دهنده برای اجرا
     * Flow: Executor submits → Escrow holds → Admin approves → Funds released
     */
    public function holdSocialTaskFunds(
        int    $executionId,
        int    $executorId,
        int    $advertiserId,
        string $reward,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['execution_id' => $executionId, 'executor_id' => $executorId, 'advertiser_id' => $advertiserId, 'amount' => $reward];
        return $this->executeWithIdempotency($idempotencyKey, $advertiserId, "hold_social_task_{$executionId}", function() use ($executionId, $executorId, $advertiserId, $reward) {
        try {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

            $result = $this->saga
                ->addStep(
                    'verify_and_lock_balance',
                    function () use ($advertiserId, $reward) {
                        $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$advertiserId])->fetch();
                        $advertiserBalance = $this->wallet->getBalanceForUpdate($advertiserId, 'irt');

                        $advertiserMoney = Money::fromString($advertiserBalance, 'irt');
                        $rewardMoney = Money::fromString($reward, 'irt');

                        if ($rewardMoney->isGreaterThan($advertiserMoney)) {
                            throw new \Exception('Insufficient advertiser balance');
                        }
                        return true;
                    },
                    function () {
                        // هیچ نیازی به جبران برای مرحله فقط‌خواندنی/قفل‌گذاری نیست
                    }
                )
                ->addStep(
                    'create_escrow_record',
                    function () use ($executionId, $executorId, $advertiserId, $reward) {
                        $result = $this->escrow->holdFunds(
                            $executionId,
                            'social_task_execution',
                            $executorId,
                            $advertiserId,
                            $reward,
                            'IRT'
                        );
                        if (!$result['ok']) {
                            throw new \Exception('Escrow creation failed: ' . ($result['error'] ?? 'Unknown error'));
                        }
                        return $result['escrow_id'];
                    },
                    function ($error) use ($executionId, $advertiserId) {
                        // Compensation: refund funds if escrow was partially created
                        $this->logger->warning('saga_compensate: cancelling escrow record', ['execution_id' => $executionId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'cancelled' WHERE order_id = ? AND order_type = ? AND status = 'pending'")
                                 ->execute([$executionId, 'social_task_execution']);
                    }
                )
                ->addStep(
                    'deduct_wallet_balance',
                    function ($escrowId) use ($executionId, $advertiserId, $reward) {
                        $this->wallet->withdraw($advertiserId, $reward, 'irt', [
                            'type' => 'social_task_escrow',
                            'execution_id' => $executionId
                        ]);
                        return $escrowId;
                    },
                    function ($error) use ($executionId, $advertiserId, $reward) {
                        // Compensation: deposit funds back to wallet if deduction partially failed
                        $this->logger->warning('saga_compensate: reverting wallet deduction', ['advertiser_id' => $advertiserId]);
                        $this->wallet->deposit($advertiserId, $reward, 'irt', [
                            'type' => 'saga_compensation',
                            'execution_id' => $executionId
                        ]);
                    }
                )
                ->execute(); // اجرای تمام مراحل پشت سر هم

            $this->logger->info('social_task.escrow_hold', [
                'execution_id' => $executionId,
                'executor_id' => $executorId,
                'adS_id' => $advertiserId,
                'amount' => $reward,
            ]);

            return ['ok' => true, 'escrow_id' => $result];

        } catch (\Throwable $e) {
            $this->logger->error('social_task.escrow_hold.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
    }

    /**
     * تایید و نگهداری مالی برای SocialTask
     * Admin approves execution → Move to in_escrow
     */
    public function confirmSocialTaskEscrow(int $executionId, int $adviserId, ?string $idempotencyKey = null): array
    {
        $payload = ['execution_id' => $executionId, 'adviser_id' => $adviserId];
        return $this->executeWithIdempotency($idempotencyKey, $adviserId, "confirm_social_task_{$executionId}", function() use ($executionId, $adviserId) {
        try {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

            $result = null;

            $this->saga->addStep(
                'confirm_escrow_hold',
                function () use ($executionId, $adviserId, &$result) {
                    $result = $this->escrow->confirmHold($executionId, 'social_task_execution', $adviserId);
                    if (!$result['ok']) {
                        throw new \Exception($result['error'] ?? 'Confirm hold failed');
                    }
                    return true;
                },
                function () {}
            )->execute();

            return $result;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
    }

    /**
     * تحویل پول به executor
     * Admin releases → Transfer to executor wallet
     */
    public function releaseSocialTaskFunds(
        int    $executionId,
        int    $executorId,
        int    $advertiserId,
        string $amount,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['execution_id' => $executionId, 'executor_id' => $executorId, 'advertiser_id' => $advertiserId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $executorId, "release_social_task_{$executionId}", function() use ($executionId, $executorId, $advertiserId, $amount) {
        try {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

            $this->saga
                ->addStep(
                    'verify_and_lock_escrow',
                    function () use ($executionId) {
                        // ✅ Lock the escrow row FOR UPDATE to resolve HIGH-NEW-02 double release race condition
                        $escrow = $this->db->query("
                            SELECT * FROM escrow_transactions 
                            WHERE order_id = ? AND order_type = ? 
                            FOR UPDATE
                        ", [$executionId, 'social_task_execution'])->fetch(\PDO::FETCH_OBJ);

                        if (!$escrow || $escrow->status !== 'in_escrow') {
                            throw new \Exception('Escrow not in proper state');
                        }
                        return $escrow->id;
                    },
                    function () {
                        // No compensation needed
                    }
                )
                ->addStep(
                    'release_escrow_funds',
                    function ($escrowId) use ($executorId) {
                        // ✅ Release via core escrow service
                        $result = $this->escrow->releaseFunds($escrowId, $executorId, 'admin_release');
                        if (!$result['ok']) {
                            throw new \Exception('Escrow release failed: ' . ($result['error'] ?? 'Unknown error'));
                        }
                        return true;
                    },
                    function ($error) use ($executionId) {
                        $this->logger->warning('saga_compensate: reverting escrow release', ['execution_id' => $executionId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'in_escrow', released_at = NULL, released_by = NULL WHERE order_id = ? AND order_type = ? AND status = 'released'")
                                 ->execute([$executionId, 'social_task_execution']);
                    }
                )
                ->addStep(
                    'deposit_wallet_balance',
                    function () use ($executorId, $amount, $executionId) {
                        // ✅ Transfer to executor wallet - calling deposit since we are inside a database transaction
                        $this->wallet->deposit($executorId, $amount, 'irt', [
                            'type' => 'social_task_reward',
                            'execution_id' => $executionId
                        ]);
                        return true;
                    },
                    function ($error) use ($executorId, $amount, $executionId) {
                        $this->logger->warning('saga_compensate: reverting wallet deposit', ['executor_id' => $executorId]);
                        $this->wallet->withdraw($executorId, $amount, 'irt', [
                            'type' => 'saga_compensation',
                            'execution_id' => $executionId
                        ]);
                    }
                )
                ->execute();

            $this->logger->info('social_task.escrow_released', [
                'execution_id' => $executionId,
                'executor_id' => $executorId,
                'amount' => $amount,
            ]);

            return ['ok' => true, 'wallet_transaction' => 'completed'];

        } catch (\Throwable $e) {
            $this->logger->error('social_task.escrow_release.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
    }

    /**
     * بازگرداندی پول به تبلیغ‌دهنده (رد شدن، dispute)
     */
    public function refundSocialTaskFunds(
        int    $executionId,
        int    $advertiserId,
        string $reason,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['execution_id' => $executionId, 'advertiser_id' => $advertiserId, 'reason' => $reason];
        return $this->executeWithIdempotency($idempotencyKey, $advertiserId, "refund_social_task_{$executionId}", function() use ($executionId, $advertiserId, $reason) {
        try {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

            $result = $this->saga
                ->addStep(
                    'verify_and_lock_escrow_refund',
                    function () use ($executionId) {
                        // ✅ Lock the escrow row FOR UPDATE to prevent concurrent refund/release races
                        $escrow = $this->db->query("
                            SELECT * FROM escrow_transactions 
                            WHERE order_id = ? AND order_type = ? 
                            FOR UPDATE
                        ", [$executionId, 'social_task_execution'])->fetch(\PDO::FETCH_OBJ);

                        if (!$escrow) {
                            throw new \Exception('No escrow found');
                        }
                        return $escrow;
                    },
                    function () {
                        // No compensation needed
                    }
                )
                ->addStep(
                    'refund_escrow_funds',
                    function ($escrow) use ($reason) {
                        // ✅ Refund via core service
                        $result = $this->escrow->refundFunds(
                            $escrow->id,
                            $escrow->buyer_id,
                            $reason,
                            'admin_refund'
                        );

                        if (!$result['ok']) {
                            throw new \Exception('Escrow refund failed: ' . ($result['error'] ?? 'Unknown error'));
                        }
                        return clone $escrow; // pass to next step
                    },
                    function ($error) use ($executionId) {
                        $this->logger->warning('saga_compensate: reverting escrow refund', ['execution_id' => $executionId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'in_escrow' WHERE order_id = ? AND order_type = ? AND status = 'refunded'")
                                 ->execute([$executionId, 'social_task_execution']);
                    }
                )
                ->addStep(
                    'deposit_refund_balance',
                    function ($escrow) use ($advertiserId, $executionId) {
                        // ✅ Return to advertiser wallet - calling deposit since we are inside a database transaction
                        $this->wallet->deposit(
                            $advertiserId,
                            $escrow->amount,
                            'irt',
                            [
                                'type' => 'social_task_refund',
                                'execution_id' => $executionId
                            ]
                        );
                        return $escrow->amount;
                    },
                    function ($error) use ($advertiserId, $executionId) {
                        $this->logger->warning('saga_compensate: reverting wallet refund deposit', ['advertiser_id' => $advertiserId]);
                        // We must fetch the amount to reverse it, or we can assume it was passed if we restructure the closure.
                        // Since we just need compensation logic, we withdraw what was just deposited.
                        $escrow = $this->escrow->getByOrder($executionId, 'social_task_execution');
                        if ($escrow) {
                            $this->wallet->withdraw($advertiserId, $escrow->amount, 'irt', [
                                'type' => 'saga_compensation',
                                'execution_id' => $executionId
                            ]);
                        }
                    }
                )
                ->execute();

            $this->logger->info('social_task.escrow_refunded', [
                'execution_id' => $executionId,
                'amount' => $result,
                'reason' => $reason,
            ]);

            return ['ok' => true, 'refund_amount' => $result];

        } catch (\Throwable $e) {
            $this->logger->error('social_task.escrow_refund.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
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
        string $amount,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['order_id' => $orderId, 'buyer_id' => $buyerId, 'seller_id' => $sellerId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $buyerId, "hold_influencer_order_{$orderId}", function() use ($orderId, $buyerId, $sellerId, $amount) {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

            $result = $this->saga
                ->addStep(
                    'verify_and_lock_buyer_balance',
                    function () use ($buyerId, $amount) {
                        // 🔒 Pessimistically lock the wallet row to prevent TOCTOU race conditions (BUG-02)
                        $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$buyerId])->fetch();

                        // ✅ Verify buyer balance
                        $buyerBalance = $this->wallet->getBalanceForUpdate($buyerId, 'irt');

                        $buyerMoney = Money::fromString($buyerBalance, 'irt');
                        $amountMoney = Money::fromString($amount, 'irt');

                        if ($amountMoney->isGreaterThan($buyerMoney)) {
                            throw new \Exception('Insufficient buyer balance');
                        }
                        return true;
                    },
                    function () {}
                )
                ->addStep(
                    'create_escrow_record',
                    function () use ($orderId, $buyerId, $sellerId, $amount) {
                        // ✅ Hold in escrow
                        $result = $this->escrow->holdFunds(
                            $orderId,
                            'influencer_order',
                            $buyerId,
                            $sellerId,
                            $amount,
                            'IRT'
                        );

                        if (!$result['ok']) {
                            throw new \Exception('Escrow creation failed: ' . ($result['error'] ?? 'Unknown error'));
                        }
                        return $result['escrow_id'];
                    },
                    function ($error) use ($orderId) {
                        $this->logger->warning('saga_compensate: cancelling influencer escrow record', ['order_id' => $orderId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'cancelled' WHERE order_id = ? AND order_type = ? AND status = 'pending'")
                                 ->execute([$orderId, 'influencer_order']);
                    }
                )
                ->addStep(
                    'deduct_wallet_balance',
                    function ($escrowId) use ($buyerId, $amount, $orderId) {
                        // ✅ Deduct from buyer wallet - calling withdraw since we are inside a database transaction
                        $this->wallet->withdraw($buyerId, $amount, 'irt', [
                            'type' => 'influencer_escrow',
                            'order_id' => $orderId
                        ]);
                        return $escrowId;
                    },
                    function ($error) use ($buyerId, $amount, $orderId) {
                        $this->logger->warning('saga_compensate: reverting wallet deduction', ['buyer_id' => $buyerId]);
                        $this->wallet->deposit($buyerId, $amount, 'irt', [
                            'type' => 'saga_compensation',
                            'order_id' => $orderId
                        ]);
                    }
                )
                ->execute();

            return ['ok' => true, 'escrow_id' => $result];
        }, $payload);
    }

    /**
     * تحویل پول به فروشنده (اینفلوئنسر)
     */
    public function releaseInfluencerOrderFunds(int $orderId, int $sellerId, string $amount, ?string $idempotencyKey = null): array
    {
        $payload = ['order_id' => $orderId, 'seller_id' => $sellerId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $sellerId, "release_influencer_order_{$orderId}", function() use ($orderId, $sellerId, $amount) {
            try {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

                $this->saga
                ->addStep(
                    'verify_and_lock_escrow',
                    function () use ($orderId) {
                        $escrow = $this->db->query(
                            "SELECT * FROM escrow_transactions WHERE order_id = ? AND order_type = ? FOR UPDATE",
                            [$orderId, 'influencer_order']
                        )->fetch(\PDO::FETCH_OBJ);

                        if (!$escrow || $escrow->status !== 'in_escrow') {
                            throw new \Exception('Invalid escrow state');
                        }
                        return $escrow->id;
                    },
                    function () {}
                )
                ->addStep(
                    'release_escrow_funds',
                    function ($escrowId) use ($sellerId) {
                        // ✅ Release & transfer
                        $result = $this->escrow->releaseFunds($escrowId, $sellerId, 'order_complete');
                        if (!$result['ok']) {
                            throw new \Exception('Escrow release failed: ' . ($result['error'] ?? 'Unknown error'));
                        }
                        return true;
                    },
                    function ($error) use ($orderId) {
                        $this->logger->warning('saga_compensate: reverting escrow release', ['order_id' => $orderId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'in_escrow', released_at = NULL, released_by = NULL WHERE order_id = ? AND order_type = ? AND status = 'released'")
                                 ->execute([$orderId, 'influencer_order']);
                    }
                )
                ->addStep(
                    'deposit_seller_balance',
                    function () use ($sellerId, $amount, $orderId) {
                        $this->wallet->deposit($sellerId, $amount, 'irt', [
                            'type' => 'influencer_order_payment',
                            'order_id' => $orderId
                        ]);
                        return true;
                    },
                    function ($error) use ($sellerId, $amount, $orderId) {
                        $this->logger->warning('saga_compensate: reverting seller wallet deposit', ['seller_id' => $sellerId]);
                        $this->wallet->withdraw($sellerId, $amount, 'irt', [
                            'type' => 'saga_compensation',
                            'order_id' => $orderId
                        ]);
                    }
                )
                ->execute();

            return ['ok' => true];

        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
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
        string $amount,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['listing_id' => $listingId, 'buyer_id' => $buyerId, 'seller_id' => $sellerId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $buyerId, "hold_vitrine_{$listingId}", function() use ($listingId, $buyerId, $sellerId, $amount) {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

            $result = $this->saga
                ->addStep(
                    'verify_and_lock_buyer_balance',
                    function () use ($buyerId, $amount) {
                        // 🔒 Pessimistically lock the wallet row to prevent TOCTOU race conditions (BUG-02)
                        $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$buyerId])->fetch();

                        // ✅ Verify buyer
                        $buyerBalance = $this->wallet->getBalanceForUpdate($buyerId, 'usdt');

                        $buyerMoney = Money::fromString($buyerBalance, 'usdt');
                        $amountMoney = Money::fromString($amount, 'usdt');

                        if ($amountMoney->isGreaterThan($buyerMoney)) {
                            throw new \Exception('Insufficient balance');
                        }
                        return true;
                    },
                    function () {}
                )
                ->addStep(
                    'create_escrow_record',
                    function () use ($listingId, $buyerId, $sellerId, $amount) {
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
                            throw new \Exception('Escrow creation failed: ' . ($result['error'] ?? 'Unknown error'));
                        }
                        return $result['escrow_id'];
                    },
                    function ($error) use ($listingId) {
                        $this->logger->warning('saga_compensate: cancelling vitrine escrow record', ['listing_id' => $listingId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'cancelled' WHERE order_id = ? AND order_type = ? AND status = 'pending'")
                                 ->execute([$listingId, 'vitrine_listing']);
                    }
                )
                ->addStep(
                    'deduct_wallet_balance',
                    function ($escrowId) use ($buyerId, $amount, $listingId) {
                        // ✅ Deduct from buyer - calling withdraw since we are inside a database transaction
                        $this->wallet->withdraw($buyerId, $amount, 'usdt', [
                            'type' => 'vitrine_escrow',
                            'listing_id' => $listingId
                        ]);
                        return $escrowId;
                    },
                    function ($error) use ($buyerId, $amount, $listingId) {
                        $this->logger->warning('saga_compensate: reverting wallet deduction', ['buyer_id' => $buyerId]);
                        $this->wallet->deposit($buyerId, $amount, 'usdt', [
                            'type' => 'saga_compensation',
                            'listing_id' => $listingId
                        ]);
                    }
                )
                ->execute();

            return ['ok' => true, 'escrow_id' => $result];
        }, $payload);
    }

    /**
     * تحویل پول به فروشنده (ویترین)
     */
    public function releaseVitrineFunds(int $listingId, int $sellerId, string $amount, ?string $idempotencyKey = null): array
    {
        $payload = ['listing_id' => $listingId, 'seller_id' => $sellerId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $sellerId, "release_vitrine_{$listingId}", function() use ($listingId, $sellerId, $amount) {
            try {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

                $result = $this->saga
                ->addStep(
                    'verify_and_lock_escrow',
                    function () use ($listingId) {
                        $escrow = $this->db->query(
                            "SELECT * FROM escrow_transactions WHERE order_id = ? AND order_type = ? FOR UPDATE",
                            [$listingId, 'vitrine_listing']
                        )->fetch(\PDO::FETCH_OBJ);

                        if (!$escrow || $escrow->status !== 'in_escrow') {
                            throw new \Exception('Invalid escrow state');
                        }
                        return $escrow->id;
                    },
                    function () {}
                )
                ->addStep(
                    'release_escrow_funds',
                    function ($escrowId) use ($sellerId) {
                        // ✅ Release
                        $result = $this->escrow->releaseFunds($escrowId, $sellerId, 'vitrine_sale_complete');
                        if (!$result['ok']) {
                            throw new \Exception('Escrow release failed: ' . ($result['error'] ?? 'Unknown error'));
                        }
                        return true;
                    },
                    function ($error) use ($listingId) {
                        $this->logger->warning('saga_compensate: reverting escrow release', ['listing_id' => $listingId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'in_escrow', released_at = NULL, released_by = NULL WHERE order_id = ? AND order_type = ? AND status = 'released'")
                                 ->execute([$listingId, 'vitrine_listing']);
                    }
                )
                ->addStep(
                    'deposit_seller_balance',
                    function () use ($sellerId, $amount, $listingId) {
                        // ✅ Calculate commission & transfer net
                        $amountMoney = Money::fromString($amount, 'usdt');
                        $commissionMoney = $amountMoney->multiply('0.05'); // 5% commission
                        $netAmountMoney = $amountMoney->subtract($commissionMoney);

                        $this->wallet->deposit($sellerId, $netAmountMoney->getAmount(), 'usdt', [
                            'type' => 'vitrine_sale',
                            'listing_id' => $listingId
                        ]);
                        return ['net_amount' => $netAmountMoney->getAmount(), 'commission' => $commissionMoney->getAmount()];
                    },
                    function ($error) use ($sellerId, $amount, $listingId) {
                        $this->logger->warning('saga_compensate: reverting seller wallet deposit', ['seller_id' => $sellerId]);
                        // Commission logic needs to be reversed
                        $amountMoney = Money::fromString($amount, 'usdt');
                        $commissionMoney = $amountMoney->multiply('0.05');
                        $netAmountMoney = $amountMoney->subtract($commissionMoney);

                        $this->wallet->withdraw($sellerId, $netAmountMoney->getAmount(), 'usdt', [
                            'type' => 'saga_compensation',
                            'listing_id' => $listingId
                        ]);
                    }
                )
                ->execute();

            return ['ok' => true, 'net_amount' => $result['net_amount'], 'commission' => $result['commission']];

        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
    }

    /**
     * بازگرداندی پول به خریدار (ویترین)
     */
    public function refundVitrineFunds(
        int    $listingId,
        int    $buyerId,
        string $reason,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['listing_id' => $listingId, 'buyer_id' => $buyerId, 'reason' => $reason];
        return $this->executeWithIdempotency($idempotencyKey, $buyerId, "refund_vitrine_{$listingId}", function() use ($listingId, $buyerId, $reason) {
        try {
            // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

            $result = $this->saga
                ->addStep(
                    'verify_and_lock_escrow_refund',
                    function () use ($listingId) {
                        $escrow = $this->escrow->getByOrder($listingId, 'vitrine_listing');
                        if (!$escrow) {
                            throw new \Exception('No escrow found');
                        }
                        return $escrow;
                    },
                    function () {}
                )
                ->addStep(
                    'refund_escrow_funds',
                    function ($escrow) use ($buyerId, $reason) {
                        // ✅ Refund
                        $result = $this->escrow->refundFunds(
                            $escrow->id,
                            $buyerId,
                            $reason,
                            'vitrine_refund'
                        );

                        if (!$result['ok']) {
                            throw new \Exception('Escrow refund failed: ' . ($result['error'] ?? 'Unknown error'));
                        }
                        return $escrow->amount;
                    },
                    function ($error) use ($listingId) {
                        $this->logger->warning('saga_compensate: reverting escrow refund', ['listing_id' => $listingId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'in_escrow' WHERE order_id = ? AND order_type = ? AND status = 'refunded'")
                                 ->execute([$listingId, 'vitrine_listing']);
                    }
                )
                ->addStep(
                    'deposit_refund_balance',
                    function ($amount) use ($buyerId, $listingId) {
                        $this->wallet->deposit($buyerId, $amount, 'usdt', [
                            'type' => 'vitrine_refund',
                            'listing_id' => $listingId
                        ]);
                        return $amount;
                    },
                    function ($error) use ($buyerId, $listingId) {
                        $this->logger->warning('saga_compensate: reverting buyer wallet deposit', ['buyer_id' => $buyerId]);
                        $escrow = $this->escrow->getByOrder($listingId, 'vitrine_listing');
                        if ($escrow) {
                            $this->wallet->withdraw($buyerId, $escrow->amount, 'usdt', [
                                'type' => 'saga_compensation',
                                'listing_id' => $listingId
                            ]);
                        }
                    }
                )
                ->execute();

            return ['ok' => true, 'refund_amount' => $result];

        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Common Dispute Handling
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Mark escrow as disputed (freezes funds)
     */
    public function markEscrowDisputed(int $orderId, string $orderType, string $reason, ?string $idempotencyKey = null): array
    {
        $payload = ['order_id' => $orderId, 'order_type' => $orderType, 'reason' => $reason];
        return $this->executeWithIdempotency($idempotencyKey, 0, "mark_disputed_{$orderType}_{$orderId}", function() use ($orderId, $orderType, $reason) {
            try {
                // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

                $result = null;

                $this->saga->addStep(
                    'mark_escrow_disputed',
                    function () use ($orderId, $orderType, $reason, &$result) {
                        $escrow = $this->escrow->getByOrder($orderId, $orderType);
                        if (!$escrow) {
                            throw new \Exception('Escrow not found');
                        }

                        $result = $this->escrow->markAsDisputed((int)$escrow->id, $reason);
                        if (!$result['ok']) {
                            throw new \Exception($result['error'] ?? 'Mark disputed failed');
                        }
                        return true;
                    },
                    function () {}
                )->execute();

                return $result;
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }, $payload);
    }

    /**
     * Resolve dispute and release/refund based on verdict
     */
    public function resolveDisputedEscrow(
        int    $orderId,
        string $orderType,
        string $verdict,
        float  $refundPercent,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['order_id' => $orderId, 'order_type' => $orderType, 'verdict' => $verdict, 'refund_percent' => $refundPercent];
        return $this->executeWithIdempotency($idempotencyKey, 0, "resolve_disputed_{$orderType}_{$orderId}", function() use ($orderId, $orderType, $verdict, $refundPercent) {
            try {
                // [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas

                $releaseAmount = null;
                $refundAmount = null;

                $this->saga->addStep(
                    'verify_and_resolve_dispute',
                    function () use ($orderId, $orderType, $verdict, $refundPercent, &$releaseAmount, &$refundAmount) {
                        $escrow = $this->escrow->getByOrder($orderId, $orderType);
                        if (!$escrow || $escrow->status !== 'disputed') {
                            throw new \Exception('Not in disputed state');
                        }

                        $scale = strtolower((string)$escrow->currency) === 'usdt' ? 8 : 4;
                        $percent = bcdiv((string)$refundPercent, '100', 8);
                        $refundAmount = \Core\ValueObjects\Money::fromString((string)((string)$escrow->amount))->multiply((string)($percent))->getAmount();
                        $releaseAmount = \Core\ValueObjects\Money::fromString((string)((string)$escrow->amount))->subtract(\Core\ValueObjects\Money::fromString((string)($refundAmount)))->getAmount();

                        $result = $this->escrow->resolveDisputePartial(
                            (int)$escrow->id,
                            (int)$escrow->buyer_id,
                            (int)$escrow->seller_id,
                            $refundAmount,
                            $releaseAmount,
                            'admin_dispute_resolution',
                            $verdict
                        );

                        if (!$result['ok']) {
                            throw new \Exception($result['error'] ?? 'Dispute resolution failed');
                        }
                        return $escrow;
                    },
                    function ($error) use ($orderId, $orderType) {
                        $this->logger->warning('saga_compensate: reverting dispute resolution', ['order_id' => $orderId, 'order_type' => $orderType]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'disputed', released_at = NULL, released_by = NULL WHERE order_id = ? AND order_type = ?")
                                 ->execute([$orderId, $orderType]);
                    }
                )->addStep(
                    'deposit_resolved_funds',
                    function ($escrow) use ($orderId, &$releaseAmount, &$refundAmount) {
                        $scale = strtolower((string)$escrow->currency) === 'usdt' ? 8 : 4;
                        $currency = $escrow->currency === 'USDT' ? 'usdt' : 'irt';

                        if (\Core\ValueObjects\Money::fromString((string)($refundAmount))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)('0')))) {
                            $this->wallet->deposit($escrow->buyer_id, $refundAmount, $currency, [
                                'type' => 'dispute_refund',
                                'order_id' => $orderId
                            ]);
                        }

                        if (\Core\ValueObjects\Money::fromString((string)($releaseAmount))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)('0')))) {
                            $this->wallet->deposit($escrow->seller_id, $releaseAmount, $currency, [
                                'type' => 'dispute_release',
                                'order_id' => $orderId
                            ]);
                        }
                        return true;
                    },
                    function (\Throwable $e) use ($orderId) {
                        $this->logger->warning('saga_compensate: reverting dispute resolution deposits', ['order_id' => $orderId]);
                    }
                )->execute();

                return ['ok' => true, 'released' => $releaseAmount, 'refunded' => $refundAmount];

            } catch (\Exception $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }, $payload);
    }

    /**
     * Cron task to automatically release expired holds back to the advertiser.
     * CRITICAL-NEW-02: Prevent funds from being locked forever if executor never submits proof.
     */
    public function releaseExpiredHolds(): int
    {
        $expiredHours = (int)$this->appSettings->get('escrow_expiry_hours', 48);
        
        $expired = $this->db->query("
            SELECT e.id, e.order_id, e.buyer_id, e.amount, e.currency
            FROM escrow_transactions e
            JOIN social_task_executions ste ON ste.id = e.order_id AND e.order_type = 'social_task_execution'
            WHERE e.status = 'pending'
              AND e.held_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
              AND ste.status IN ('pending', 'started')
        ", [$expiredHours])->fetchAll(\PDO::FETCH_OBJ);
        
        foreach ($expired as $row) {
            $this->refundSocialTaskFunds(
                (int)$row->order_id,
                (int)$row->buyer_id,
                'auto_expired_after_' . $expiredHours . 'h'
            );
        }
        
        return count($expired);
    }
}

