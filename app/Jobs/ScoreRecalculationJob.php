<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AntiFraud\FraudScoreService;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * ScoreRecalculationJob
 *
 * محاسبه مجدد امتیازات کاربرانی که Score آنها احتمالاً دچار corruption شده:
 * - مقایسه projection (user_scores) با جمع رویدادهای واقعی (score_events)
 * - اصلاح اختلاف از طریق commitDeltaToDatabase
 * - بازسازی score از روی event log (replay) برای کاربران مشکوک
 */
class ScoreRecalculationJob
{
    // حداکثر کاربران بررسی‌شده در هر اجرا
    private const BATCH_SIZE = 100;
    // آستانه اختلاف قابل قبول (مقادیر کمتر از این نادیده گرفته می‌شوند)
    private const DIFF_TOLERANCE = 0.001;

    public function __construct(
        private FraudScoreService $scoreService,
        private Database $db,
        private LoggerInterface $logger
    ) {}

    public function handle(array $data = []): void
    {
        $domain = (string) ($data['domain'] ?? 'fraud');

        $this->recalculateDomain($domain);
    }

    private function recalculateDomain(string $domain): void
    {
        try {
            // کاربرانی که اختلاف بین projection و event_log دارند
            $corrupted = $this->db->fetchAll(
                "SELECT us.user_id,
                        us.score AS projected_score,
                        COALESCE(SUM(se.delta), 0) AS real_score
                 FROM user_scores us
                 LEFT JOIN score_events se
                       ON se.entity_id = us.user_id
                      AND se.entity_type = 'user'
                      AND se.domain = us.domain
                 WHERE us.domain = ?
                 GROUP BY us.user_id, us.score
                 HAVING ABS(us.score - COALESCE(SUM(se.delta), 0)) > ?
                 LIMIT ?",
                [$domain, self::DIFF_TOLERANCE, self::BATCH_SIZE]
            );

            $fixed = 0;
            foreach ($corrupted as $row) {
                try {
                    $projected = (float) $row->projected_score;
                    $real      = (float) $row->real_score;
                    $diff      = $real - $projected;

                    // تصحیح projection با delta اختلاف
                    $this->scoreService->commitDeltaToDatabase(
                        (int) $row->user_id,
                        $domain,
                        $diff,
                        'score_recalculation',
                        [
                            'projected'  => $projected,
                            'real'       => $real,
                            'corrected'  => true,
                            'job'        => 'ScoreRecalculationJob',
                        ]
                    );

                    $fixed++;
                    $this->logger->warning('score.recalculated', [
                        'user_id'   => $row->user_id,
                        'domain'    => $domain,
                        'projected' => $projected,
                        'real'      => $real,
                        'diff'      => $diff,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('score.recalculation_row_failed', [
                        'user_id' => $row->user_id ?? null,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('score.recalculation_job_completed', [
                'domain'    => $domain,
                'checked'   => count($corrupted),
                'fixed'     => $fixed,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('score.recalculation_job_failed', [
                'domain' => $domain,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
