<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class CronJob extends Model
{
    protected static string $table = 'cron_jobs';

    public function logRun(array $payload): int
    {
        return (int)$this->db->table(self::$table)
            ->insert($payload);
    }

    public function getRecentRuns(int $limit = 50): array
    {
        return $this->db->table(self::$table)
            ->select('*')
            ->orderBy('started_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function getFailedRuns(int $limit = 20): array
    {
        return $this->db->table(self::$table)
            ->select('*')
            ->where('status', '=', 'failed')
            ->orderBy('started_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }
}
