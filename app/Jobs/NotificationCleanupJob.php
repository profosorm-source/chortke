<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * NotificationCleanupJob
 *
 * حذف اعلان‌های قدیمی از جدول notifications طبق سیاست نگهداری.
 */
class NotificationCleanupJob
{
    private const DEFAULT_RETENTION_DAYS = 365;

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {}

    public function handle(array $data = []): void
    {
        $retentionDays = (int) ($data['retention_days'] ?? self::DEFAULT_RETENTION_DAYS);
        $retentionDays = max(30, min(3650, $retentionDays));

        try {
            $deleted = (int) $this->db->execute(
                "DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$retentionDays]
            );

            $this->logger->info('notification.cleanup_completed', [
                'deleted'        => $deleted,
                'retention_days' => $retentionDays,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('notification.cleanup_failed', [
                'error'          => $e->getMessage(),
                'retention_days' => $retentionDays,
            ]);
        }
    }
}
