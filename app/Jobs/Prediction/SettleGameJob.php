<?php

declare(strict_types=1);

namespace App\Jobs\Prediction;

class SettleGameJob
{
    public function __construct(
        private \Core\Database $db,
        private \App\Services\StateMachineService $stateMachine,
        private \App\Models\PredictionBet $betModel,
        private \App\Services\AuditTrail $auditTrail,
        private ?\App\Services\ScoreService $scoreService = null
    ) {}

    public function handle(int $gameId, string $result, int $adminId): array
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
            $commissionAmt   = \Core\ValueObjects\Money::fromString((string)($totalPool))->multiply((string)($commissionRatio))->getAmount();
            $prizePool       = \Core\ValueObjects\Money::fromString((string)($totalPool))->subtract(\Core\ValueObjects\Money::fromString((string)($commissionAmt)))->getAmount();

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
                'commission_amt' => round((float)$commissionAmt, 6),
                'prize_pool'     => round((float)$prizePool, 6),
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
                    $payout = \Core\ValueObjects\Money::fromString((string)($ratio))->multiply((string)($prizePool))->getAmount();
                    
                    // BUG-P3 Fix: Ensure we don't overpay due to rounding
                    $totalPaidOut = \Core\ValueObjects\Money::fromString((string)($totalPaidOut))->add(\Core\ValueObjects\Money::fromString((string)($payout)))->getAmount();
                    if (\Core\ValueObjects\Money::fromString((string)($totalPaidOut))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($prizePool)))) {
                        $payout = \Core\ValueObjects\Money::fromString((string)($payout))->subtract(\Core\ValueObjects\Money::fromString((string)(bcsub($totalPaidOut)))->getAmount(), 8);
                    }

                    $this->_payWinner($bet, (float)$payout, $gameId);
                    $summary['winners_paid']++;
                }

                // علامت‌گذاری بازندگان
                $allBets = $this->betModel->getByGame($gameId);
                foreach ($allBets as $bet) {
                    if ($bet->prediction !== $result && $bet->status === 'pending') {
                        $this->betModel->markLost((int)$bet->id);
                        if ($this->scoreService) {
                            $this->scoreService->applyDelta('user', (int)$bet->user_id, 'prediction_accuracy', -2.0, 'prediction_loss_'.$gameId);
                        }
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
}
