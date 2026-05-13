<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PredictionGame;
use App\Models\PredictionBet;
use Core\Database;

use App\Contracts\LoggerInterface;
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
    public function __construct(
        private Database      $db,
        private PredictionGame $gameModel,
        private PredictionBet  $betModel,
        private WalletService  $walletService,
        LoggerInterface       $logger
    ) {
        parent::__construct($logger);
    }

    // ─────────────────────────────────────────────────────────────────
    // Place Bet — atomic: بررسی + کسر + ثبت در یک تراکنش
    // ─────────────────────────────────────────────────────────────────

    /**
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function placeBet(int $userId, int $gameId, string $prediction, float $amount): array
    {
        // اعتبارسنجی اولیه (قبل از transaction)
        if (!in_array($prediction, ['home', 'away', 'draw'], true)) {
            throw new \InvalidArgumentException('پیش‌بینی باید home، away یا draw باشد.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ شرط باید بیشتر از صفر باشد.');
        }

        try {
            $this->db->beginTransaction();

            // قفل بازی برای خواندن اطلاعات معتبر
            $game = $this->db->fetch(
                "SELECT * FROM prediction_games WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$gameId]
            );

            if (!$game) {
                throw new \RuntimeException('بازی یافت نشد.');
            }
            if ($game->status !== 'open') {
                throw new \RuntimeException('این بازی برای شرط‌بندی باز نیست.');
            }
            if (strtotime($game->bet_deadline) < time()) {
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

            // کسر موجودی از کیف پول
            $debitResult = $this->walletService->withdraw(
                $userId,
                $amount,
                'usdt',
                [
                    'type'        => 'prediction_bet',
                    'description' => "شرط بازی #{$gameId}: {$game->title}",
                    'game_id'     => $gameId,
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
            if ((bool)($game->winners_paid ?? false)) {
                throw new \RuntimeException('جوایز این بازی قبلاً پرداخت شده است.');
            }

            // ثبت نتیجه
            $this->db->execute(
                "UPDATE prediction_games
                 SET result = ?, status = 'finished', finished_at = NOW(), settled_by = ?
                 WHERE id = ?",
                [$result, $adminId, $gameId]
            );

            // محاسبه استخر
            $dist = $this->betModel->getDistribution($gameId);
            $totalPool    = (float)$dist->total_pool;
            $commission   = (float)($game->commission_percent ?? 5) / 100;
            $prizePool    = $totalPool * (1 - $commission);
            $commissionAmt = $totalPool * $commission;

            // استخر برندگان
            $winnerPool = match ($result) {
                'home'  => (float)$dist->pool_home,
                'away'  => (float)$dist->pool_away,
                'draw'  => (float)$dist->pool_draw,
                default => 0.0,
            };

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

            if (empty($winnerBets)) {
                // هیچ برنده‌ای نیست — همه شرط‌ها برگشت داده می‌شوند
                foreach ($this->betModel->getPendingByGame($gameId) as $bet) {
                    $this->_refundBet($bet, $gameId, 'no_winners');
                    $summary['winners_paid']++;
                }
                $summary['no_winners'] = true;
            } else {
                // پرداخت به برندگان
                foreach ($winnerBets as $bet) {
                    $share = $winnerPool > 0
                        ? ((float)$bet->amount_usdt / $winnerPool) * $prizePool
                        : 0.0;
                    $payout = round($share, 6);
                    $this->_payWinner($bet, $payout, $gameId);
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

            // علامت پرداخت شده
            $this->db->execute(
                "UPDATE prediction_games SET winners_paid = 1, paid_at = NOW() WHERE id = ?",
                [$gameId]
            );

            $this->db->commit();

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

