<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Database;
use App\Contracts\LoggerInterface;
class SystemCleanupJob
{
    public function handle(array $payload, ): bool
    {
        $db = $context->db;
        $logger = $context->logger;

        $days = (int)($payload['retention_days'] ?? 30);
        $auditDays = (int)($payload['audit_retention_days'] ?? 180);

        $logger->info("system_cleanup.started", ['days' => $days, 'audit_days' => $auditDays]);

        try {
            $thresholdDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $auditThresholdDate = date('Y-m-d H:i:s', strtotime("-{$auditDays} days"));

            // 1. Clean system logs (usually the heaviest)
            $logsDeleted = $db->execute(
                "DELETE FROM system_logs WHERE created_at < ?",
                [$thresholdDate]
            );

            // 2. Clean outbox events that are completed
            $outboxDeleted = $db->execute(
                "DELETE FROM outbox_events WHERE status = 'processed' AND updated_at < ?",
                [$thresholdDate]
            );

            // 3. Clean failed jobs
            $failedJobsDeleted = $db->execute(
                "DELETE FROM failed_jobs WHERE failed_at < ?",
                [$thresholdDate]
            );

            // 4. Clean DLQ / Failed outbox events older than 30 days
            $dlqDeleted = $db->execute(
                "DELETE FROM outbox_events WHERE status IN ('dlq', 'failed') AND updated_at < ?",
                [$thresholdDate]
            );

            // 5. Clean old audit trails (longer retention) - ARCHIVE instead of delete
            $auditThresholdDateStr = $auditThresholdDate;
            $auditToArchive = $db->fetchAll("SELECT * FROM audit_trail WHERE created_at < ?", [$auditThresholdDateStr]);
            if (!empty($auditToArchive)) {
                $archiveFile = BASE_PATH . '/storage/logs/audit_archive_' . date('Y-m') . '.jsonl';
                $archiveHandle = @fopen($archiveFile, 'a');
                if ($archiveHandle) {
                    foreach ($auditToArchive as $row) {
                        fwrite($archiveHandle, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
                    }
                    fclose($archiveHandle);
                }
            }
            $auditDeleted = $db->execute(
                "DELETE FROM audit_trail WHERE created_at < ?",
                [$auditThresholdDateStr]
            );

            // 6. Clean orphaned search projections
            // Find entity types to clean
            $entityTypes = ['user', 'ad', 'task']; // standard mapping
            $orphanedSearchDeleted = 0;
            foreach ($entityTypes as $eType) {
                $tableName = $eType === 'user' ? 'users' : ($eType === 'ad' || $eType === 'task' ? 'ads' : null);
                if ($tableName) {
                    $deleted = $db->execute(
                        "DELETE sp FROM search_projections sp LEFT JOIN {$tableName} t ON sp.entity_id = t.id AND sp.entity_type = ? WHERE sp.entity_type = ? AND t.id IS NULL",
                        [$eType, $eType]
                    );
                    $orphanedSearchDeleted += (int)$deleted;
                }
            }

            // 8. Clean expired Idempotency keys (older than 24h by default in IdempotencyKey class)
            $idempotencyDeleted = 0;
            try {
                $idempotencyService = \Core\Container::getInstance()->make(\Core\IdempotencyKey::class);
                $idempotencyDeleted = $idempotencyService->cleanup(false);
            } catch (\Throwable $e) {
                // Ignore if container lacks IdempotencyKey or DB error
            }

            $logger->info("system_cleanup.completed", [
                'logs_deleted' => $logsDeleted,
                'outbox_deleted' => $outboxDeleted,
                'dlq_deleted' => $dlqDeleted,
                'failed_jobs_deleted' => $failedJobsDeleted,
                'audit_deleted' => $auditDeleted,
                'orphaned_search_deleted' => $orphanedSearchDeleted,
                'idempotency_deleted' => $idempotencyDeleted,
            ]);

            return true;
        } catch (\Throwable $e) {
            $logger->error("system_cleanup.failed", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
