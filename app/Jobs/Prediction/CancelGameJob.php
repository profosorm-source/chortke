<?php

declare(strict_types=1);

namespace App\Jobs\Prediction;

class CancelGameJob
{
    public function __construct(
        private \Core\Database $db,
        private \App\Services\StateMachineService $stateMachine,
        private \App\Models\PredictionBet $betModel
    ) {}

    public function handle(int $gameId, int $adminId): array
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
}
