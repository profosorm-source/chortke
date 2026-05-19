<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * ActivityLog Model
 */
class ActivityLog extends Model
{
    protected static string $table = 'activity_logs';

    /**
     * دریافت لاگ‌های اخیر
     * L-01: Removed cache() from Model - Caching must be in Service layer
     */
    public function getRecent(int $limit = 50, ?int $userId = null, ?string $action = null, ?string $channel = null): array
    {
        $limit = max(1, min(500, $limit));
        
        $query = $this->db->table('activity_logs as al')
            ->select('al.*', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'al.user_id', '=', 'u.id')
            ->whereNull('al.deleted_at');

        if ($userId !== null) {
            $query->where('al.user_id', '=', $userId);
        }
        if ($action !== null) {
            $query->where('al.action', '=', $action);
        }
        if ($channel !== null) {
            $query->where('al.channel', '=', $channel);
        }

        return $query->orderBy('al.created_at', 'DESC')->limit($limit)->get();
    }

    /**
     * دریافت با صفحه‌بندی
     */
    public function getPaginated(
        int $page = 1,
        int $perPage = 20,
        ?int $userId = null,
        ?string $action = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $channel = null
    ): array {
        // C-04: Enforce hard ceiling on pagination depths to prevent unbounded pagination (LOW-02)
        $page = max(1, min(10000, $page));
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $query = $this->db->table('activity_logs as al')
            ->leftJoin('users as u', 'al.user_id', '=', 'u.id')
            ->whereNull('al.deleted_at');

        if ($userId !== null) {
            $query->where('al.user_id', '=', $userId);
        }
        if ($action !== null) {
            $query->where('al.action', '=', $action);
        }
        if ($channel !== null) {
            $query->where('al.channel', '=', $channel);
        }
        if ($search !== null) {
            $searchClean = addcslashes($search, '%_');
            $like = "%{$searchClean}%";
            $query->where(function($q) use ($like) {
                $q->where('al.description', 'LIKE', $like)
                  ->orWhere('al.action', 'LIKE', $like)
                  ->orWhere('u.full_name', 'LIKE', $like)
                  ->orWhere('u.email', 'LIKE', $like);
            });
        }
        if ($dateFrom !== null) {
            $query->where('al.created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== null) {
            $query->where('al.created_at', '<=', $dateTo . ' 23:59:59');
        }

        // Count total
        $total = $query->count();

        // Fetch paginated data
        $rows = $query->select('al.*', 'u.full_name', 'u.email', 'u.avatar')
            ->orderBy('al.created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * دریافت یک رکورد با ID به همراه اطلاعات کاربر
     */
    public function findById(int $id): ?object
    {
        $result = $this->db->table('activity_logs as al')
            ->select('al.*', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'al.user_id', '=', 'u.id')
            ->where('al.id', '=', $id)
            ->whereNull('al.deleted_at')
            ->first();

        return $result ?: null;
    }

    /**
     * حذف موقت (Soft Delete) لاگ‌های قدیمی
     */
    public function softDeleteOlderThan(int $days = 90): int
    {
        $totalUpdated = 0;
        $chunkSize = 1000;
        $dateLimit = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        do {
            $affected = $this->db->table(static::$table)
                ->whereNull('deleted_at')
                ->where('created_at', '<', $dateLimit)
                ->limit($chunkSize)
                ->update(['deleted_at' => date('Y-m-d H:i:s')]);

            $totalUpdated += $affected;
            if ($affected > 0) {
                usleep(100000); // 100ms delay between chunks
            }
        } while ($affected > 0);

        return $totalUpdated;
    }

    /**
     * حذف فیزیکی لاگ‌های قدیمی به صورت تکه‌تکه (Chunked)
     */
    public function deleteOlderThanChunked(int $days = 90, int $chunkSize = 5000): int
    {
        $totalDeleted = 0;
        $maxIterations = 100;
        $dateLimit = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        for ($i = 0; $i < $maxIterations; $i++) {
            $affected = $this->db->table(static::$table)
                ->where('created_at', '<', $dateLimit)
                ->limit($chunkSize)
                ->delete();

            $totalDeleted += $affected;

            if ($affected < $chunkSize) {
                break;
            }
        }

        return $totalDeleted;
    }

    /**
     * دریافت لیست action های یونیک
     */
    public function getUniqueActions(): array
    {
        $rows = $this->db->table(static::$table)
            ->selectRaw('DISTINCT action')
            ->whereNull('deleted_at')
            ->orderBy('action', 'ASC')
            ->get();

        return array_column($rows, 'action');
    }

    /**
     * شمارش تعداد روزهای فعال کاربر در ماه جاری
     */
    public function countActiveDays(int $userId, string $monthStart): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(DISTINCT DATE(created_at)) as days
             FROM " . static::$table . "
             WHERE user_id = ? AND created_at >= ?",
            [$userId, $monthStart]
        );

        return (int)($row->days ?? 0);
    }
}
