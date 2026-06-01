<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\User;
use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * CronService — لایه Service برای عملیات Cron Jobs
 */
class CronService
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\Gamification\XpService $xpService;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Gamification\XpService $xpService
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->xpService = $xpService;

    }

    public function applyInactivityScoreDecay(): array
    {
        // دریافت همه کاربران فعال
        $users = $this->db->fetchAll("
            SELECT id, email, level_slug FROM users
            WHERE status = 'active' AND deleted_at IS NULL
        ");

        $processed = 0;

        foreach ($users as $userData) {
            $userId = (int)$userData->id;

            // پیدا کردن تاریخ آخرین فعالیت کاربر
            $lastActivityStr = $this->db->fetchColumn("
                SELECT MAX(created_at) FROM activity_logs
                WHERE user_id = ?
            ", [$userId]);

            if (!$lastActivityStr) {
                // اگر هیچ لاگی نبود، تاریخ ثبت‌نام را به عنوان آخرین فعالیت در نظر می‌گیریم
                $lastActivityStr = $this->db->fetchColumn("
                    SELECT created_at FROM users WHERE id = ?
                ", [$userId]);
            }

            if (!$lastActivityStr) {
                continue;
            }

            $lastActivity = new \DateTime($lastActivityStr);
            $now = new \DateTime();
            $interval = $now->diff($lastActivity);
            $inactiveDays = $interval->days;

            if ($inactiveDays >= 1) {
                $processed++;
                // ساخت مدل کاربر و ارسال به سرویس
                $user = new User($this->db); 
                $user = $user->find($userId); // فرض بر این است که متد find وجود دارد، در غیر این صورت فیلدها را مپ می‌کنیم
                
                if (!$user) {
                    // Fallback اگر find در مدل به شکل دیگری است
                    $user = (object)['id' => $userId, 'level_slug' => $userData->level_slug];
                }
                
                $this->xpService->applyDecay($user, $inactiveDays);
            }
        }

        return [
            'success' => true,
            'processed_users' => $processed,
            'message' => "بررسی ریزش امتیاز عدم فعالیت برای {$processed} کاربر انجام شد."
        ];
    }

    /**
     * فلاش کردن بافر امتیازات از Redis به Database
     */
    public function flushScoreEventsBuffer(int $batchSize = 1000): array
    {
        try {
            $scoreModel = new \App\Models\Score($this->db);
            $flushed = $scoreModel->flushBuffer($batchSize);
            return [
                'success' => true,
                'message' => "{$flushed} score events flushed to database.",
                'count' => $flushed
            ];
        } catch (\Throwable $e) {
            $this->logger->error('cron.flush_score_events.failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در فلاش کردن بافر امتیازات',
                'error' => $e->getMessage()
            ];
        }
    }
}
