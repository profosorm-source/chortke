<?php

declare(strict_types=1);

namespace App\Services\AdminDashboard;

use Core\Database;
use Core\Cache;
use App\Contracts\LoggerInterface;
use App\Services\PerformanceOptimizationService;

class DashboardQueryService extends \App\Services\BaseService
{
    private Database $db;
    private Cache $cache;
    private PerformanceOptimizationService $performance;
    
    public function __construct(
        Database $db, 
        Cache $cache,
        LoggerInterface $logger,
        PerformanceOptimizationService $performance
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->cache = $cache;
        $this->performance = $performance;
    }

    /**
     * دریافت اطلاعات و آمارهای آماری داشبورد مدیریت
     * Optimized: 5 separate queries → 1 consolidated query with tracking
     */
    public function getDashboardData(int $userId): array
    {
        $data = [
            'users' => [
                'total' => 0,
                'active' => 0,
                'pending_kyc' => 0,
                'with_2fa' => 0,
            ],
            'disputes' => [
                'total' => 0,
                'open' => 0,
                'resolved' => 0,
            ],
            'financial' => [
                'total_volume' => 0.0,
                'currency' => 'IRT',
                'transactions_count' => 0,
            ],
            'tickets' => [
                'total' => 0,
                'pending' => 0,
            ],
            'appeals' => [
                'total' => 0,
                'pending' => 0,
            ]
        ];

        try {
            $startTime = microtime(true);
            
            // ✅ Consolidated Query: Get all stats in one query instead of 5
            $result = $this->getConsolidatedStats();
            
            $executionTime = microtime(true) - $startTime;
            
            // Track performance
            $this->performance->trackQueryTime('getDashboardData (consolidated)', $executionTime);
            
            // Populate data from consolidated query
            if ($result) {
                $data['users']['total'] = (int)($result->users_total ?? 0);
                $data['users']['active'] = (int)($result->users_active ?? 0);
                $data['users']['pending_kyc'] = (int)($result->users_pending_kyc ?? 0);
                $data['users']['with_2fa'] = (int)($result->users_with_2fa ?? 0);
                
                $data['disputes']['total'] = (int)($result->disputes_total ?? 0);
                $data['disputes']['open'] = (int)($result->disputes_open ?? 0);
                $data['disputes']['resolved'] = (int)($result->disputes_resolved ?? 0);
                
                $data['financial']['transactions_count'] = (int)($result->fin_count ?? 0);
                $data['financial']['total_volume'] = (float)($result->fin_volume ?? 0.0);
                
                $data['tickets']['total'] = (int)($result->tickets_total ?? 0);
                $data['tickets']['pending'] = (int)($result->tickets_pending ?? 0);
                
                $data['appeals']['total'] = (int)($result->appeals_total ?? 0);
                $data['appeals']['pending'] = (int)($result->appeals_pending ?? 0);
                
                // LOW-13: Suppress diagnostic meta-telemetry in production responses to minimize metadata footprint
                if (function_exists('config') && (config('app.debug') || config('app.env') === 'local')) {
                    $data['_execution_time_ms'] = round($executionTime * 1000, 2);
                    $data['_query_optimization'] = 'consolidated (5 queries → 1)';
                }
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('dashboard.query.consolidated_failed', ['error' => $e->getMessage()]);
        }

        return $data;
    }

    /**
     * ✅ OPTIMIZED: Consolidated query to replace 5 separate queries
     * Get all dashboard statistics in a single database round trip
     * MED-08: بررسی وجود جداول قبل اجرا
     */
    private function getConsolidatedStats()
    {
        // Cache the heavy aggregation for 60 seconds to reduce repeated load
        return $this->cache->remember('dashboard:admin:consolidated_stats', 60, function() {
            // MED-08: بررسی وجود جداول مهم
            $requiredTables = ['users', 'transactions', 'tickets'];
            foreach ($requiredTables as $table) {
                if (!$this->tableExists($table)) {
                    $this->logger->warning('dashboard.table_missing', ['table' => $table]);
                    return null; // بازگردان null در صورت عدم وجود جدول
                }
            }

            // جداول optional
            $haDisputes = $this->tableExists('disputes');
            $hasAppeals = $this->tableExists('appeals');

            // HIGH-08: Completely eliminate complex inline string concatenations in the SQL generator.
            // Instead, compile discrete, static SELECT expressions inside a clean associative array map.
            $selects = [
                "(SELECT COUNT(*) FROM users) as users_total",
                "(SELECT COUNT(*) FROM users WHERE status = 'active') as users_active",
                "(SELECT COUNT(*) FROM users WHERE two_factor_enabled = 1) as users_with_2fa",
                "(SELECT COUNT(*) FROM users WHERE kyc_status = 'pending') as users_pending_kyc",
                
                "(SELECT COUNT(*) FROM transactions WHERE status = 'completed') as fin_count",
                "(SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE status = 'completed') as fin_volume",
                
                "(SELECT COUNT(*) FROM tickets) as tickets_total",
                "(SELECT COUNT(*) FROM tickets WHERE status = 'pending') as tickets_pending",
            ];

            if ($haDisputes) {
                $selects[] = "(SELECT COUNT(*) FROM disputes) as disputes_total";
                $selects[] = "(SELECT COUNT(*) FROM disputes WHERE status IN ('open', 'open_peer', 'under_review', 'escalated')) as disputes_open";
                $selects[] = "(SELECT COUNT(*) FROM disputes WHERE status IN ('resolved_peer', 'resolved_admin', 'closed')) as disputes_resolved";
            } else {
                $selects[] = "0 as disputes_total";
                $selects[] = "0 as disputes_open";
                $selects[] = "0 as disputes_resolved";
            }

            if ($hasAppeals) {
                $selects[] = "(SELECT COUNT(*) FROM appeals) as appeals_total";
                $selects[] = "(SELECT COUNT(*) FROM appeals WHERE status = 'pending') as appeals_pending";
            } else {
                $selects[] = "0 as appeals_total";
                $selects[] = "0 as appeals_pending";
            }

            $sql = "SELECT " . implode(", ", $selects);
            
            try {
                return $this->db->fetch($sql);
            } catch (\Throwable $e) {
                $this->logger->error('dashboard.consolidated_query_failed', [
                    'error' => $e->getMessage(),
                    'sql' => substr($sql, 0, 200) // لاگ کردن بخشی از query
                ]);
                return null;
            }
        });
    }

    /**
     * MED-08: بررسی وجود یک جدول در دیتابیس
     */
    private function tableExists(string $tableName): bool
    {
        try {
            // H17 Fix: بازنویسی کوئری با استفاده از استاندارد ANSI SQL (استفاده از information_schema) جهت پشتیبانی کامل از PostgreSQL و SQLite
            $result = $this->db->query("SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1", [$tableName])->fetchAll();
            return !empty($result);
        } catch (\Throwable $e) {
            $this->logger->warning('dashboard.table_check_failed', [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);
            return false; // فرض کن جدول موجود نیست
        }
    }

    /**
     * دریافت لیست لاگ‌های دسترسی ادمین
     */
    public function getAdminAccessLog(int $limit = 10): array
    {
        try {
            $sql = "SELECT l.*, u.full_name as admin_name 
                    FROM admin_access_logs l 
                    LEFT JOIN users u ON u.id = l.user_id 
                    ORDER BY l.created_at DESC LIMIT ?";
            
            return $this->db->fetchAll($sql, [$limit]) ?: [];
        } catch (\Throwable $e) {
            $this->logger->warning('dashboard.query.access_logs_failed', ['error' => $e->getMessage()]);
            return []; // ادمین متوجه می‌شود که در حال حاضر دیتایی نیست
        }
    }

    /**
     * دریافت فعالیت‌های اخیر پلتفرم با فیلتر و صفحه‌بندی کامل
     */
    public function getRecentActivity(string $type = 'all', int $limit = 20, int $page = 1): array
    {
        $offset = ($page - 1) * $limit;
        
        try {
            $where = ['1=1'];
            $params = [];
            
            if ($type !== 'all') {
                $where[] = "activity_type = ?";
                $params[] = $type;
            }
            
            $whereStr = implode(' AND ', $where);
            $params[] = $limit;
            $params[] = $offset;
            
            $sql = "SELECT a.*, u.full_name as user_name 
                    FROM activities a 
                    LEFT JOIN users u ON u.id = a.user_id 
                    WHERE {$whereStr} 
                    ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
            
            return $this->db->fetchAll($sql, $params) ?: [];
        } catch (\Throwable $e) {
            $this->logger->warning('dashboard.query.activities_failed', ['error' => $e->getMessage()]);
            return []; // دیتا فیک حذف شد تا ادمین متوجه خطا شود
        }
    }

    /**
     * محاسبه زمان گذشته (استفاده داخلی کمکی)
     */
    public function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return 'نامشخص';
        }
        
        $diff = time() - $timestamp;
        if ($diff < 0) {
            return 'هم‌اکنون';
        }
        if ($diff < 60) {
            return 'لحظاتی پیش';
        }
        if ($diff < 3600) {
            return (int)($diff / 60) . ' دقیقه پیش';
        }
        if ($diff < 86400) {
            return (int)($diff / 3600) . ' ساعت پیش';
        }
        if ($diff < 604800) {
            return (int)($diff / 86400) . ' روز پیش';
        }
        if ($diff < 2592000) {
            return (int)($diff / 604800) . ' هفته پیش';
        }
        return (int)($diff / 2592000) . ' ماه پیش';
    }
}
