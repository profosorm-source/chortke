<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * UnifiedTaskService - هاب مرکزی مدیریت و فیلترینگ یکپارچه انواع تسک‌ها (SEO, Social, Custom)
 */
class UnifiedTaskService
{


    private \Core\Database $db;
    public function __construct(
        \Core\Database $db
    )
    {        $this->db = $db;

        
        }

    /**
     * دریافت تسک‌های معتبر و انجام نشده برای کاربر به صورت تجمیعی
     */
    public function getTasksForExecutor(int $userId, array $filters = [], int $limit = 30, int $offset = 0): array
    {
        // ۱. فیلترهای پایه: آگهی فعال، دارای ظرفیت و از انواع مجاز
        $where = [
            "a.status = 'active'",
            "a.remaining_count > 0",
            "a.deleted_at IS NULL",
            "a.type IN ('seo', 'social', 'custom_task')"
        ];
        $params = [];

        // ۲. استثنا قائل شدن برای Adtube (طبق دستور کاربر: یوتیوب باید از لیست اصلی مستثنی باشد و جدا مدیریت شود)
        $where[] = "(a.platform != 'youtube' OR a.platform IS NULL)";

        $where[] = "NOT EXISTS (SELECT 1 FROM social_task_executions WHERE ad_id = a.id AND executor_id = ? AND status NOT IN ('cancelled','expired'))";
        $where[] = "NOT EXISTS (SELECT 1 FROM seo_executions WHERE ad_id = a.id AND user_id = ? AND status != 'rejected')";
        $where[] = "NOT EXISTS (SELECT 1 FROM custom_task_submissions WHERE task_id = a.id AND worker_id = ? AND status != 'rejected')";
        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userId;

        // ۴. اعمال فیلترهای درخواستی کاربر (Smart Filters)
        if (!empty($filters['type'])) {
            $where[] = "a.type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['platform'])) {
            $where[] = "a.platform = ?";
            $params[] = $filters['platform'];
        }

        if (!empty($filters['min_price'])) {
            $where[] = "a.price_per_task >= ?";
            $params[] = (float)$filters['min_price'];
        }

        if (!empty($filters['max_price'])) {
            $where[] = "a.price_per_task <= ?";
            $params[] = (float)$filters['max_price'];
        }

        if (!empty($filters['q'])) {
            $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
            $sanitizedQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$filters['q']);
            $like = '%' . $sanitizedQ . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // ۵. مرتب‌سازی هوشمند (Smart Ordering)
        $orderBy = "a.created_at DESC";
        if (!empty($filters['sort'])) {
            switch ($filters['sort']) {
                case 'highest_price':
                    $orderBy = "a.price_per_task DESC";
                    break;
                case 'lowest_price':
                    $orderBy = "a.price_per_task ASC";
                    break;
                case 'oldest':
                    $orderBy = "a.created_at ASC";
                    break;
            }
        }

        $whereSql = implode(" AND ", $where);

        // اجرای نهایی کوئری به صورت کاملاً بهینه
        $sql = "SELECT a.*, u.full_name as advertiser_name
                FROM ads a
                LEFT JOIN users u ON u.id = a.user_id
                WHERE {$whereSql}
                ORDER BY {$orderBy}
                LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * شمارش تعداد کل تسک‌های قابل نمایش برای صفحه‌بندی
     */
    public function countTasksForExecutor(int $userId, array $filters = []): int
    {
        $where = [
            "a.status = 'active'",
            "a.remaining_count > 0",
            "a.deleted_at IS NULL",
            "a.type IN ('seo', 'social', 'custom_task')"
        ];
        $params = [];

        $where[] = "(a.platform != 'youtube' OR a.platform IS NULL)";

        $where[] = "NOT EXISTS (SELECT 1 FROM social_task_executions WHERE ad_id = a.id AND executor_id = ? AND status NOT IN ('cancelled','expired'))";
        $where[] = "NOT EXISTS (SELECT 1 FROM seo_executions WHERE ad_id = a.id AND user_id = ? AND status != 'rejected')";
        $where[] = "NOT EXISTS (SELECT 1 FROM custom_task_submissions WHERE task_id = a.id AND worker_id = ? AND status != 'rejected')";
        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userId;

        if (!empty($filters['type'])) {
            $where[] = "a.type = ?";
            $params[] = $filters['type'];
        }
        if (!empty($filters['platform'])) {
            $where[] = "a.platform = ?";
            $params[] = $filters['platform'];
        }
        if (!empty($filters['min_price'])) {
            $where[] = "a.price_per_task >= ?";
            $params[] = (float)$filters['min_price'];
        }
        if (!empty($filters['max_price'])) {
            $where[] = "a.price_per_task <= ?";
            $params[] = (float)$filters['max_price'];
        }
        if (!empty($filters['q'])) {
            $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
            $sanitizedQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$filters['q']);
            $like = '%' . $sanitizedQ . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(" AND ", $where);
        $sql = "SELECT COUNT(*) FROM ads a WHERE {$whereSql}";

        $result = $this->db->fetch($sql, $params);
        // Extract count safely
        $count = 0;
        if ($result) {
            $arr = (array)$result;
            $count = (int)reset($arr);
        }
        return $count;
    }

    /**
     * دریافت لیست پلتفرم‌های موجود جهت اعمال در فرم‌های فیلترینگ
     */
    public function getAvailablePlatforms(): array
    {
        return $this->db->fetchAll("SELECT DISTINCT platform FROM ads WHERE platform IS NOT NULL AND platform != 'youtube'");
    }

    /**
     * executor کے اعدادوشمار حاصل کریں
     */
    public function getExecutorStats(int $userId): array
    {
        $stats = $this->db->fetch("
            SELECT 
                (SELECT COUNT(*) FROM social_task_executions WHERE executor_id = ? AND status = 'completed') as social_done,
                (SELECT COUNT(*) FROM seo_executions WHERE user_id = ? AND status = 'completed') as seo_done,
                (SELECT COUNT(*) FROM custom_task_submissions WHERE user_id = ? AND status = 'approved') as custom_done,
                (SELECT COUNT(*) FROM social_task_executions WHERE executor_id = ? AND status IN ('pending', 'in_progress')) as social_pending,
                (SELECT COUNT(*) FROM seo_executions WHERE user_id = ? AND status = 'pending') as seo_pending,
                (SELECT COUNT(*) FROM custom_task_submissions WHERE user_id = ? AND status = 'pending') as custom_pending,
                (SELECT COALESCE(SUM(price_per_task * remaining_count), 0) FROM ads WHERE type IN ('seo', 'social', 'custom_task') AND status = 'active') as available_earnings
        ", [$userId, $userId, $userId, $userId, $userId, $userId]);

        $socialDone = (int)($stats->social_done ?? 0);
        $seoDone = (int)($stats->seo_done ?? 0);
        $customDone = (int)($stats->custom_done ?? 0);
        $totalCompleted = $socialDone + $seoDone + $customDone;

        $socialPending = (int)($stats->social_pending ?? 0);
        $seoPending = (int)($stats->seo_pending ?? 0);
        $customPending = (int)($stats->custom_pending ?? 0);
        $totalPending = $socialPending + $seoPending + $customPending;

        return [
            'total_completed' => $totalCompleted,
            'social_completed' => $socialDone,
            'seo_completed' => $seoDone,
            'custom_completed' => $customDone,
            'pending_total' => $totalPending,
            'social_pending' => $socialPending,
            'seo_pending' => $seoPending,
            'custom_pending' => $customPending,
            'available_earnings' => (float)($stats->available_earnings ?? 0),
        ];
    }
}
