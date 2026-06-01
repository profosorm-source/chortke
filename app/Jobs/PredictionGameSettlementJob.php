<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PredictionService;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * PredictionGameSettlementJob
 *
 * تسویه خودکار بازی‌های پیش‌بینی که نتیجه‌شان مشخص شده اما هنوز settle نشده‌اند.
 * - بازی‌هایی که status='closed' و result ثبت شده اما winners_paid=0 هستند را settle می‌کند.
 * - از settleGame() موجود در PredictionService استفاده می‌کند (DRY).
 */
class PredictionGameSettlementJob
{
    // شناسه سیستم (ادمین cron)
    private const SYSTEM_ADMIN_ID = 0;

    private PredictionService $predictionService;
    private Database $db;
    private LoggerInterface $logger;
    public function __construct(
        PredictionService $predictionService,
        Database $db,
        LoggerInterface $logger
    ) {        $this->predictionService = $predictionService;
        $this->db = $db;
        $this->logger = $logger;
}

    public function handle(array $data = []): void
    {
        try {
            // بازی‌هایی که نتیجه دارند اما تسویه نشده‌اند
            $games = $this->db->fetchAll(
                "SELECT id, result
                 FROM prediction_games
                 WHERE status = 'closed'
                   AND result IS NOT NULL
                   AND result != ''
                   AND winners_paid = 0
                   AND finished_at IS NULL
                 ORDER BY bet_deadline ASC
                 LIMIT 50"
            );

            $settled = 0;
            $failed  = 0;

            foreach ($games as $game) {
                try {
                    $this->predictionService->settleGame(
                        (int) $game->id,
                        (string) $game->result,
                        self::SYSTEM_ADMIN_ID
                    );
                    $settled++;
                    $this->logger->info('prediction.auto_settled', [
                        'game_id' => $game->id,
                        'result'  => $game->result,
                    ]);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->logger->error('prediction.auto_settle_failed', [
                        'game_id' => $game->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('prediction.settlement_job_completed', [
                'settled' => $settled,
                'failed'  => $failed,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('prediction.settlement_job_fatal', ['error' => $e->getMessage()]);
        }
    }
}
