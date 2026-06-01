<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\LevelUpgradedEvent;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * HandleLevelUpgraded
 *
 * پس از ارتقاء سطح کاربر:
 * ۱. ارسال notification تبریک لِوِل‌آپ
 * ۲. ثبت در جدول audit برای traceability
 * ۳. اعطای badge متناظر با سطح جدید
 */
class HandleLevelUpgraded
{
    /**
     * نقشه badge به سطح کاربر
     */
    private const LEVEL_BADGE_MAP = [
        'bronze'   => ['badge_silver',  'نقره‌ای'],
        'silver'   => ['badge_gold',    'طلایی'],
        'gold'     => ['badge_diamond', 'الماس'],
        'diamond'  => ['badge_elite',   'النخبة'],
    ];

    private NotificationServiceInterface $notificationService;
    private LoggerInterface $logger;
    private Database $db;
    public function __construct(
        NotificationServiceInterface $notificationService,
        LoggerInterface $logger,
        Database $db
    ) {        $this->notificationService = $notificationService;
        $this->logger = $logger;
        $this->db = $db;
}

    public function handle(LevelUpgradedEvent $event): void
    {
        $userId   = $event->userId;
        $oldLevel = $event->oldLevel;
        $newLevel = $event->newLevel;

        // ۱. Notification
        $this->sendLevelUpNotification($userId, $oldLevel, $newLevel);

        // ۲. ثبت در Audit Trail
        $this->recordAudit($userId, $oldLevel, $newLevel, $event->reason);

        // ۳. اعطای Badge
        $this->awardBadge($userId, $oldLevel, $newLevel);
    }

    private function sendLevelUpNotification(int $userId, string $oldLevel, string $newLevel): void
    {
        try {
            $this->notificationService->send(
                $userId,
                'level_upgraded',
                '🎉 ارتقاء سطح!',
                "تبریک! سطح شما از {$oldLevel} به {$newLevel} ارتقاء یافت.",
                [
                    'old_level' => $oldLevel,
                    'new_level' => $newLevel,
                ],
                null,
                null,
                'high'
            );
        } catch (\Throwable $e) {
            $this->logger->error('level.notification_failed', [
                'user_id'   => $userId,
                'new_level' => $newLevel,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function recordAudit(int $userId, string $oldLevel, string $newLevel, string $reason): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO audit_logs (user_id, event, details, created_at)
                 VALUES (?, 'level.upgraded', ?, NOW())"
            )->execute([
                $userId,
                json_encode([
                    'old_level' => $oldLevel,
                    'new_level' => $newLevel,
                    'reason'    => $reason,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('level.audit_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function awardBadge(int $userId, string $oldLevel, string $newLevel): void
    {
        if (!isset(self::LEVEL_BADGE_MAP[$oldLevel])) {
            return;
        }

        [$badgeSlug, $badgeLabel] = self::LEVEL_BADGE_MAP[$oldLevel];

        try {
            // Upsert badge: در صورت وجود قبلی، updated_at را بروز می‌کند
            $this->db->prepare(
                "INSERT INTO user_badges (user_id, badge_slug, awarded_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE awarded_at = NOW()"
            )->execute([$userId, $badgeSlug]);

            $this->logger->info('level.badge_awarded', [
                'user_id'    => $userId,
                'badge_slug' => $badgeSlug,
                'badge'      => $badgeLabel,
                'new_level'  => $newLevel,
            ]);
        } catch (\Throwable $e) {
            // جدول ممکن است هنوز وجود نداشته باشد؛ fallback به log
            $this->logger->warning('level.badge_award_failed', [
                'user_id'    => $userId,
                'badge_slug' => $badgeSlug,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
