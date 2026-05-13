<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * UnifiedTaskService - هاب مرکزی مدیریت و فیلترینگ یکپارچه انواع تسک‌ها (SEO, Social, Custom)
 */
class UnifiedTaskService extends BaseService
{
    private Database $db;

    public function __construct(Database $db, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->db = $db;
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

        // ۳. فیلتر هوشمند: عدم نمایش تسک‌هایی که کاربر قبلاً انجام داده است (جلوگیری از تکرار)
        // این کوئری ترکیبی، تمامی جداول اجرا (Executions) را در یک NOT EXISTS هوشمند چک می‌کند.
        $where[] = "NOT EXISTS (
            SELECT 1 FROM (
                SELECT ad_id, executor_id FROM social_task_executions WHERE status NOT IN ('cancelled','expired')
                UNION ALL
                SELECT ad_id, user_id as executor_id FROM seo_executions WHERE status NOT IN ('rejected')
                UNION ALL
                SELECT task_id as ad_id, user_id as executor_id FROM custom_task_submissions WHERE status NOT IN ('rejected')
            ) AS executions
            WHERE executions.ad_id = a.id AND executions.executor_id = ?
        )";
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
            $like = '%' . $filters['q'] . '%';
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

        $where[] = "NOT EXISTS (
            SELECT 1 FROM (
                SELECT ad_id, executor_id FROM social_task_executions WHERE status NOT IN ('cancelled','expired')
                UNION ALL
                SELECT ad_id, user_id as executor_id FROM seo_executions WHERE status NOT IN ('rejected')
                UNION ALL
                SELECT task_id as ad_id, user_id as executor_id FROM custom_task_submissions WHERE status NOT IN ('rejected')
            ) AS executions
            WHERE executions.ad_id = a.id AND executions.executor_id = ?
        )";
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
}
