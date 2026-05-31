<?php

declare(strict_types=1);

namespace App\Jobs\Prediction;

class PlaceBetJob
{
    public function __construct(
        private ?\App\Services\Shared\IdempotencyService $idempotencyService = null,
        private ?\App\Services\DistributedLockService $lockService = null,
        private \Core\Database $db,
        private \App\Models\PredictionBet $betModel,
        private ?\App\Domain\Financial\Services\FinancialEscrowService $escrowService = null,
        private \App\Contracts\WalletServiceInterface $walletService
    ) {}

    public function handle(int $userId, int $gameId, string $prediction, float $amount, ?string $idempotencyKey = null): array
    {
        // اعتبارسنجی اولیه (قبل از transaction)
        if (!in_array($prediction, ['home', 'away', 'draw'], true)) {
            throw new \InvalidArgumentException('پیش‌بینی باید home، away یا draw باشد.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ شرط باید بیشتر از صفر باشد.');
        }

        $payload = [
            'user_id' => $userId,
            'game_id' => $gameId,
            'prediction' => $prediction,
            'amount' => $amount,
        ];

        $explicitKey = $idempotencyKey !== null && $idempotencyKey !== ''
            ? $idempotencyKey
            : \Core\IdempotencyKey::generateFromPayload('prediction_place_bet', $payload);

        return $this->idempotencyService->execute('prediction.placeBet', $userId, $payload, function () use (
            $userId,
            $gameId,
            $prediction,
            $amount,
            $explicitKey
        ) {
            return $this->lockService->synchronized("prediction_bet_{$userId}_{$gameId}", function() use (
                $userId,
                $gameId,
                $prediction,
                $amount,
                $explicitKey
            ) {
                try {
                    $this->db->beginTransaction();

                    // P-3 Fix: Lock game and check deadline using authoritative database NOW() time to prevent TOCTOU race conditions
                    $game = $this->db->fetch(
                        "SELECT *, CASE WHEN bet_deadline > NOW() THEN 1 ELSE 0 END as is_deadline_valid 
                         FROM prediction_games 
                         WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                        [$gameId]
                    );

                    if (!$game) {
                        throw new \RuntimeException('بازی یافت نشد.');
                    }
                    if ($game->status !== 'open') {
                        throw new \RuntimeException('این بازی برای شرط‌بندی باز نیست.');
                    }
                    if (!(bool)($game->is_deadline_valid ?? false)) {
                        throw new \RuntimeException('مهلت ثبت شرط تمام شده است.');
                    }
                    if ($amount < (float)$game->min_bet_usdt) {
                        throw new \InvalidArgumentException("حداقل مبلغ شرط {$game->min_bet_usdt} USDT است.");
                    }
                    if ($amount > (float)$game->max_bet_usdt) {
                        throw new \InvalidArgumentException("حداکثر مبلغ شرط {$game->max_bet_usdt} USDT است.");
                    }

                    // بررسی شرط تکراری — با FOR UPDATE داخل transaction
                    if ($this->betModel->userHasBetForUpdate($userId, $gameId)) {
                        throw new \RuntimeException('شما قبلاً در این بازی شرط‌بندی کرده‌اید.');
                    }

                    if (isset($this->escrowService) && $this->escrowService) {
                        $debitResult = $this->escrowService->holdFunds(
                            $gameId,
                            'prediction_bet',
                            $userId,
                            -1, // System seller
                            (string)$amount,
                            'usdt'
                        );
                        if (empty($debitResult) || empty($debitResult['ok'])) {
                            throw new \RuntimeException($debitResult['error'] ?? 'خطا در بلوکه کردن مبلغ شرط.');
                        }
                        $transactionId = $debitResult['escrow_id'] ?? null;
                    } else {
                        // کسر موجودی از کیف پول با استفاده از withdrawInTransaction
                        // تا عملیات برداشت و ایجاد شرط در یک تراکنش مشترک باقی بماند.
                        $debitResult = $this->walletService->withdrawInTransaction(
                            $userId,
                            $amount,
                            'usdt',
                            [
                                'type'        => 'prediction_bet',
                                'description' => "شرط بازی #{$gameId}: {$game->title}",
                                'game_id'     => $gameId,
                                'idempotency_key' => $explicitKey,
                            ]
                        );

                        if (!$debitResult['success']) {
                            throw new \RuntimeException($debitResult['message'] ?? 'موجودی کافی نیست.');
                        }
                        $transactionId = $debitResult['transaction_id'] ?? null;
                    }

                    // ثبت شرط
                    $bet = $this->betModel->create([
                        'user_id'     => $userId,
                        'game_id'     => $gameId,
                        'prediction'  => $prediction,
                        'amount_usdt' => $amount,
                        'transaction_id' => $transactionId, // Store escrow_id or tx_id if schema allows it
                    ]);

                    if (!$bet) {
                        throw new \RuntimeException('خطا در ثبت شرط. لطفاً دوباره تلاش کنید.');
                    }

                    $this->db->commit();

                    return [
                        'success' => true,
                        'bet_id'  => $bet->id,
                        'message' => 'شرط‌بندی با موفقیت ثبت شد.',
                    ];

                } catch (\Exception $e) {
                    $this->db->rollBack();
                    throw $e;
                }
            });
        }, $explicitKey);
    }
}
