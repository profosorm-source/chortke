<?php

declare(strict_types=1);

namespace App\Commands;

use App\Jobs\SystemCleanupJob;
class SystemCleanupCommand
{
    private ;

    public function __construct()
    {
        }

    public function run(array $args): void
    {
        $retentionDays = 30;
        $auditRetentionDays = 180;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--days=')) {
                $retentionDays = (int)str_replace('--days=', '', $arg);
            }
            if (str_starts_with($arg, '--audit-days=')) {
                $auditRetentionDays = (int)str_replace('--audit-days=', '', $arg);
            }
        }

        echo "Starting System Cleanup (Retention: {$retentionDays} days, Audit: {$auditRetentionDays} days)...\n";

        $job = new SystemCleanupJob();
        $result = $job->handle([
            'retention_days' => $retentionDays,
            'audit_retention_days' => $auditRetentionDays
        ], $this->context);

        if ($result) {
            echo "System cleanup completed successfully.\n";
        } else {
            echo "System cleanup encountered errors. Check system logs.\n";
        }
    }
}
