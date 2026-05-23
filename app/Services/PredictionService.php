<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WalletServiceInterface;
use App\Models\PredictionGame;
use App\Models\PredictionBet;
use Core\Database;

use App\Contracts\LoggerInterface;
use App\Services\StateMachineService;

/**
 * PredictionService — منطق اصلی سیستم پیش‌بینی
 *
 * الگو: Pari-Mutuel Pool
 *   - کل شرط‌ها یک استخر مشترک تشکیل می‌دهند
 *   - پس از کسر کمیسیون سایت، استخر خالص بین برندگان
 *     به نسبت مبلغ شرط هر برنده تقسیم می‌شود
 *   - اگر هیچ برنده‌ای نباشد، شرط‌ها برگشت داده می‌شوند
 *   - اگر بازی لغو شود، همه شرط‌ها برگشت داده می‌شوند
 */
class PredictionService extends \App\Services\BaseService
{
    private Database $db;
    private PredictionGame $gameModel;
    private PredictionBet $betModel;
    private WalletServiceInterface $walletService;
    private \App\Services\AuditTrail $auditTrail;
    private StateMachineService $stateMachine;

    public function __construct(
        Database      $db,
        PredictionGame $gameModel,
        PredictionBet  $betModel,
        WalletServiceInterface  $walletService,
        LoggerInterface       $logger,
        \App\Services\AuditTrail $auditTrail,
        ?StateMachineService $stateMachine = null
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->gameModel = $gameModel;
        $this->betModel = $betModel;
        $this->walletService = $walletService;
        $this->auditTrail = $auditTrail;
        $this->stateMachine = $stateMachine ?? new StateMachineService($logger, $db);
    }

    // ─────────────────────────────────────────────────────────────────
    // Place Bet — atomic: بررسی + کسر + ثبت در یک تراکنش
    // ─────────────────────────────────────────────────────────────────

    /**
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function placeBet(int $userId, int $gameId, string $prediction, float $amount, ?string $idempotencyKey = null): array
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

        return $this->idempotent('prediction.placeBet', $userId, $payload, function () use (
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

                // ثبت شرط
                $bet = $this->betModel->create([
                    'user_id'     => $userId,
                    'game_id'     => $gameId,
                    'prediction'  => $prediction,
                    'amount_usdt' => $amount,
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
        }, $explicitKey);
    }

    // ─────────────────────────────────────────────────────────────────
    // Settle Game — توزیع جوایز به برندگان (Pari-Mutuel)
    // ─────────────────────────────────────────────────────────────────

    /**
     * نتیجه را ثبت کرده و جوایز را به برندگان پرداخت می‌کند.
     *
     * @throws \RuntimeException
     */
    public function settleGame(int $gameId, string $result, int $adminId): array
    {
        if (!in_array($result, ['home', 'away', 'draw'], true)) {
            throw new \InvalidArgumentException('نتیجه باید home، away یا draw باشد.');
        }

        try {
            $this->db->beginTransaction();

            // قفل بازی
            $game = $this->db->fetch(
                "SELECT * FROM prediction_games WHERE id = ? FOR UPDATE",
                [$gameId]
            );

            if (!$game) {
                throw new \RuntimeException('بازی یافت نشد.');
            }
            if (!in_array($game->status, ['open', 'closed'], true)) {
                throw new \RuntimeException('این بازی قابل تسویه نیست (وضعیت فعلی: ' . $game->status . ')');
            }

            if (!$this->stateMachine->canTransition('prediction_game', $game->status, 'finished')) {
                throw new \RuntimeException('تغییر وضعیت بازی به finished مجاز نیست.');
            }
            // P-4 Fix: Authoritatively acquire exclusive settle-lock on winners_paid immediately.
            // If another admin settles concurrently, one will have affectedRows = 0 and rollback instantly.
            $affected = $this->db->execute(
                "UPDATE prediction_games 
                 SET winners_paid = 1 
                 WHERE id = ? AND winners_paid = 0",
                [$gameId]
            );

            if ($affected === 0) {
                $this->db->rollBack();
                throw new \RuntimeException('جوایز این بازی قبلاً پرداخت شده است.');
            }

            // ثبت نتیجه
            $this->db->execute(
                "UPDATE prediction_games
                 SET result = ?, status = 'finished', finished_at = NOW(), settled_by = ?
                 WHERE id = ?",
                [$result, $adminId, $gameId]
            );

            // محاسبه استخر با BCMath (BUG-P2 Fix)
            $dist = $this->betModel->getDistribution($gameId);
            $totalPool    = (string)($dist->total_pool ?? '0');
            $commissionPercent = (string)($game->commission_percent ?? '5');
            
            $commissionRatio = bcdiv($commissionPercent, '100', 8);
            $commissionAmt   = bcmul($totalPool, $commissionRatio, 8);
            $prizePool       = bcsub($totalPool, $commissionAmt, 8);

            // استخر برندگان
            $winnerPool = match ($result) {
                'home'  => (string)($dist->pool_home ?? '0'),
                'away'  => (string)($dist->pool_away ?? '0'),
                'draw'  => (string)($dist->pool_draw ?? '0'),
                default => '0',
            };

            // H-P5: Audit Trail - log the result before distributing
            $this->auditTrail->record('prediction.settle_start', $adminId, [
                'game_id' => $gameId,
                'result' => $result,
                'total_pool' => $totalPool,
                'prize_pool' => $prizePool
            ]);

            $summary = [
                'game_id'        => $gameId,
                'result'         => $result,
                'total_pool'     => $totalPool,
                'commission_pct' => $game->commission_percent,
                'commission_amt' => round($commissionAmt, 6),
                'prize_pool'     => round($prizePool, 6),
                'winners_paid'   => 0,
                'losers_marked'  => 0,
            ];

            $winnerBets = $this->betModel->getWinnersByGame($gameId, $result);

            if (bccomp($winnerPool, '0', 8) <= 0) {
                // هیچ برنده‌ای نیست — همه شرط‌ها برگشت داده می‌شوند
                foreach ($this->betModel->getPendingByGame($gameId) as $bet) {
                    $this->_refundBet($bet, $gameId, 'no_winners');
                    $summary['winners_paid']++;
                }
                $summary['no_winners'] = true;
            } else {
                // پرداخت به برندگان
                $totalPaidOut = '0';
                foreach ($winnerBets as $bet) {
                    // share = (bet_amount / winner_pool) * prize_pool
                    $ratio = bcdiv((string)$bet->amount_usdt, $winnerPool, 12);
                    $payout = bcmul($ratio, $prizePool, 8);
                    
                    // BUG-P3 Fix: Ensure we don't overpay due to rounding
                    $totalPaidOut = bcadd($totalPaidOut, $payout, 8);
                    if (bccomp($totalPaidOut, $prizePool, 8) > 0) {
                        $payout = bcsub($payout, bcsub($totalPaidOut, $prizePool, 8), 8);
                    }

                    $this->_payWinner($bet, (float)$payout, $gameId);
                    $summary['winners_paid']++;
                }

                // علامت‌گذاری بازندگان
                $allBets = $this->betModel->getByGame($gameId);
                foreach ($allBets as $bet) {
                    if ($bet->prediction !== $result && $bet->status === 'pending') {
                        $this->betModel->markLost((int)$bet->id);
                        $summary['losers_marked']++;
                    }
                }
            }

            // Update remaining status fields in the database
            $this->db->execute(
                "UPDATE prediction_games 
                 SET paid_at = NOW(), status = 'finished', 
                     finished_at = NOW(), settled_by = ?, result = ?
                 WHERE id = ?",
                [$adminId, $result, $gameId]
            );

            $this->db->commit();
            
            // P-5: Log immutable structured audit trail for dispute resolution
            $this->auditTrail->record('prediction.settled', $adminId, [
                'game_id' => $gameId,
                'result' => $result,
                'total_pool' => $totalPool,
                'winners_paid' => count($winnerBets),
                'timestamp' => microtime(true)
            ]);

            return ['success' => true, 'summary' => $summary];

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Cancel Game — لغو بازی و برگشت همه شرط‌ها
    // ─────────────────────────────────────────────────────────────────

    /**
     * @throws \RuntimeException
     */
    public function cancelGame(int $gameId, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            $game = $this->db->fetch(
                "SELECT * FROM prediction_games WHERE id = ? FOR UPDATE",
                [$gameId]
            );

            if (!$game) {
                throw new \RuntimeException('بازی یافت نشد.');
            }
            if (!in_array($game->status, ['open', 'closed'], true)) {
                throw new \RuntimeException('فقط بازی‌های باز یا بسته قابل لغو هستند.');
            }

            if (!$this->stateMachine->canTransition('prediction_game', $game->status, 'cancelled')) {
                throw new \RuntimeException('تغییر وضعیت بازی به cancelled مجاز نیست.');
            }

            // لغو بازی
            $this->db->execute(
                "UPDATE prediction_games
                 SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?
                 WHERE id = ?",
                [$adminId, $gameId]
            );

            // برگشت همه شرط‌های فعال
            $bets    = $this->betModel->getPendingByGame($gameId);
            $refunded = 0;

            foreach ($bets as $bet) {
                $this->_refundBet($bet, $gameId, 'game_cancelled');
                $refunded++;
            }

            $this->db->commit();

            return [
                'success'        => true,
                'message'        => "بازی لغو شد و {$refunded} شرط برگشت داده شد.",
                'refunded_count' => $refunded,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────

    private function _payWinner(object $bet, float $payout, int $gameId): void
    {
        // واریز به کیف پول
        $this->walletService->deposit(
            (int)$bet->user_id,
            $payout,
            'usdt',
            [
                'type'        => 'prediction_win',
                'description' => "پاداش پیش‌بینی بازی #{$gameId}",
                'game_id'     => $gameId,
                'bet_id'      => $bet->id,
            ]
        );

        // علامت‌گذاری شرط
        $this->betModel->markWon((int)$bet->id, $payout);
    }

    private function _refundBet(object $bet, int $gameId, string $reason): void
    {
        $this->walletService->deposit(
            (int)$bet->user_id,
            (float)$bet->amount_usdt,
            'usdt',
            [
                'type'        => 'prediction_refund',
                'description' => "برگشت شرط بازی #{$gameId} ({$reason})",
                'game_id'     => $gameId,
                'bet_id'      => $bet->id,
            ]
        );

        $this->betModel->markRefunded((int)$bet->id);
    }
}

