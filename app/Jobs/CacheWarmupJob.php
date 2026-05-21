<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\Database;

/**
 * CacheWarmupJob
 *
 * گرم کردن کش‌های حیاتی پس از cold start یا هنگامی که TTL منقضی می‌شود:
 * - Feature flags مهم
 * - تنظیمات نرخ کمیسیون
 * - لیست بنرهای فعال
 * - آمار داشبورد ادمین (summary-level)
 */
class CacheWarmupJob
{
    private const FEATURE_FLAG_TTL  = 600;  // 10 دقیقه
    private const CONFIG_TTL        = 3600; // 1 ساعت
    private const DASHBOARD_TTL     = 300;  // 5 دقیقه

    public function __construct(
        private Database $db,
        private Cache $cache,
        private LoggerInterface $logger
    ) {}

    public function handle(array $data = []): void
    {
        $warmed = 0;

        $warmed += $this->warmFeatureFlags();
        $warmed += $this->warmSystemConfigs();
        $warmed += $this->warmDashboardSummary();

        $this->logger->info('cache.warmup.completed', ['warmed_keys' => $warmed]);
    }

    /**
     * گرم کردن Feature Flags فعال (جلوگیری از DB hit در هر درخواست)
     */
    private function warmFeatureFlags(): int
    {
        try {
            $flags = $this->db->fetchAll(
                "SELECT name, is_enabled, rollout_percentage, config_json
                 FROM feature_flags
                 WHERE deleted_at IS NULL"
            );

            foreach ($flags as $flag) {
                $this->cache->putSeconds(
                    'feature_flag:' . $flag->name,
                    [
                        'is_enabled'         => (bool) $flag->is_enabled,
                        'rollout_percentage' => (int) $flag->rollout_percentage,
                        'config'             => json_decode($flag->config_json ?? '{}', true),
                    ],
                    self::FEATURE_FLAG_TTL
                );
            }

            return count($flags);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.warmup.feature_flags_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * گرم کردن تنظیمات پایه سیستم از جدول system_configs
     */
    private function warmSystemConfigs(): int
    {
        try {
            $configs = $this->db->fetchAll(
                "SELECT config_key, config_value FROM system_configs LIMIT 500"
            );

            foreach ($configs as $cfg) {
                $this->cache->putSeconds(
                    'sys_config:' . $cfg->config_key,
                    $cfg->config_value,
                    self::CONFIG_TTL
                );
            }

            return count($configs);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.warmup.system_configs_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * گرم کردن آمار خلاصه داشبورد ادمین
     */
    private function warmDashboardSummary(): int
    {
        try {
            $summary = [
                'total_users'        => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL"),
                'active_ads'         => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM advertisements WHERE status = 'active'"),
                'pending_kyc'        => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM kyc_verifications WHERE status = 'pending'"),
                'pending_withdrawal' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'"),
                'warmed_at'          => date('Y-m-d H:i:s'),
            ];

            $this->cache->putSeconds('admin_dashboard_summary', $summary, self::DASHBOARD_TTL);

            return 1;
        } catch (\Throwable $e) {
            $this->logger->warning('cache.warmup.dashboard_summary_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
