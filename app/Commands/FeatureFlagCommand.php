<?php

declare(strict_types=1);

/**
 * Feature Flag CLI Management
 * 
 * استفاده:
 * php cli.php feature:list
 * php cli.php feature:enable crypto_wallet
 * php cli.php feature:disable crypto_wallet
 * php cli.php feature:status crypto_wallet
 * php cli.php feature:create new_feature "توضیحات"
 * php cli.php feature:delete old_feature
 * php cli.php feature:rollout lottery 50
 * php cli.php feature:schedule lottery "2026-05-01 00:00:00" "2026-05-31 23:59:59"
 */

namespace App\Commands;

use App\Services\FeatureFlagService;
use App\Contracts\LoggerInterface;

class FeatureFlagCommand
{
    private FeatureFlagService $featureService;
    private LoggerInterface $logger;
    
    public function __construct(LoggerInterface $logger, FeatureFlagService $featureService)
    {
        $this->logger = $logger;
        $this->featureService = $featureService;
    }
    
    /**
     * لیست همه فیچرها
     */
    public function list(): void
    {
        $features = $this->featureService->getAll();
        
        if (empty($features)) {
            echo "هیچ فیچری یافت نشد.\n";
            return;
        }
        
        echo "\n┌─────────────────────────────────────────────────────────────────────────┐\n";
        echo "│                         Feature Flags List                             │\n";
        echo "├──────────────────────────────┬──────────┬──────────┬───────────────────┤\n";
        echo "│ Name                         │ Enabled  │ Rollout  │ Priority          │\n";
        echo "├──────────────────────────────┼──────────┼──────────┼───────────────────┤\n";
        
        foreach ($features as $feature) {
            $name = str_pad(substr($feature->name, 0, 28), 28);
            $enabled = $feature->enabled ? '✓ Yes' : '✗ No ';
            $enabled = str_pad($enabled, 8);
            $rollout = str_pad($feature->enabled_percentage . '%', 8);
            $priority = str_pad((string)($feature->priority ?? 0), 17);
            
            echo "│ {$name} │ {$enabled} │ {$rollout} │ {$priority} │\n";
        }
        
        echo "└──────────────────────────────┴──────────┴──────────┴───────────────────┘\n";
        
        $stats = $this->featureService->getStats();
        echo "\nآمار: {$stats['total']} فیچر | {$stats['enabled']} فعال | {$stats['disabled']} غیرفعال\n\n";
    }
    
    /**
     * فعال کردن فیچر
     */
    public function enable(string $name): void
    {
        $feature = $this->featureService->findByName($name);
        
        if (!$feature) {
            echo "❌ فیچر '{$name}' یافت نشد.\n";
            exit(1);
        }
        
        if ($feature->enabled) {
            echo "ℹ️  فیچر '{$name}' از قبل فعال است.\n";
            return;
        }
        
        if ($this->featureService->update($name, ['enabled' => true])) {
            echo "✅ فیچر '{$name}' با موفقیت فعال شد.\n";
        } else {
            echo "❌ خطا در فعال‌سازی فیچر.\n";
            exit(1);
        }
    }
    
    /**
     * غیرفعال کردن فیچر
     */
    public function disable(string $name): void
    {
        $feature = $this->featureService->findByName($name);
        
        if (!$feature) {
            echo "❌ فیچر '{$name}' یافت نشد.\n";
            exit(1);
        }
        
        if (!$feature->enabled) {
            echo "ℹ️  فیچر '{$name}' از قبل غیرفعال است.\n";
            return;
        }
        
        if ($this->featureService->update($name, ['enabled' => false])) {
            echo "✅ فیچر '{$name}' با موفقیت غیرفعال شد.\n";
        } else {
            echo "❌ خطا در غیرفعال‌سازی فیچر.\n";
            exit(1);
        }
    }
    
    /**
     * نمایش وضعیت فیچر
     */
    public function status(string $name): void
    {
        $feature = $this->featureService->findByName($name);
        
        if (!$feature) {
            echo "❌ فیچر '{$name}' یافت نشد.\n";
            exit(1);
        }
        
        echo "\n╔═══════════════════════════════════════════════════════════════╗\n";
        echo "║              Feature Status: {$name}\n";
        echo "╠═══════════════════════════════════════════════════════════════╣\n";
        echo "║ Enabled:         " . ($feature->enabled ? '✓ Yes' : '✗ No') . "\n";
        echo "║ Description:     {$feature->description}\n";
        echo "║ Rollout:         {$feature->enabled_percentage}%\n";
        echo "║ Priority:        " . ($feature->priority ?? 0) . "\n";
        
        if ($feature->enabled_for_roles ?? null) {
            $roles = json_decode($feature->enabled_for_roles, true);
            echo "║ Allowed Roles:   " . implode(', ', $roles) . "\n";
        }
        
        if ($feature->enabled_from ?? null) {
            echo "║ Enabled From:    {$feature->enabled_from}\n";
        }
        
        if ($feature->enabled_until ?? null) {
            echo "║ Enabled Until:   {$feature->enabled_until}\n";
        }
        
        if ($feature->depends_on ?? null) {
            $deps = json_decode($feature->depends_on, true);
            echo "║ Dependencies:    " . implode(', ', $deps) . "\n";
        }
        
        if ($feature->tags ?? null) {
            $tags = json_decode($feature->tags, true);
            echo "║ Tags:            " . implode(', ', $tags) . "\n";
        }
        
        echo "║ Created:         {$feature->created_at}\n";
        echo "║ Updated:         {$feature->updated_at}\n";
        echo "╚═══════════════════════════════════════════════════════════════╝\n\n";
        
        // نمایش متریک‌ها
        $metrics = $this->featureService->getMetrics($name, 24);
        
        if (!empty($metrics)) {
            echo "📊 Metrics (Last 24 hours):\n";
            foreach ($metrics as $metric) {
                echo "   - Total checks: {$metric->total_checks}\n";
                echo "   - Allowed: {$metric->allowed_count}\n";
                echo "   - Denied: {$metric->denied_count}\n";
                if (isset($metric->avg_response_time)) {
                    echo "   - Avg response: " . round((float)$metric->avg_response_time, 2) . "ms\n";
                }
            }
            echo "\n";
        }
    }
    
    /**
     * ایجاد فیچر جدید
     */
    public function create(string $name, string $description): void
    {
        try {
            if ($this->featureService->create([
                'name' => $name,
                'description' => $description,
                'enabled' => false,
            ])) {
                echo "✅ فیچر '{$name}' با موفقیت ایجاد شد.\n";
            }
        } catch (\Exception $e) {
            echo "❌ خطا: {$e->getMessage()}\n";
            exit(1);
        }
    }
    
    /**
     * حذف فیچر
     */
    public function delete(string $name, bool $force = false): void
    {
        if ($force) {
            $confirm = 'yes';
        } elseif (!defined('STDIN') || !stream_isatty(STDIN)) {
            echo "❌ خطا: عملیات حذف در محیط غیرتعاملی غیرمجاز است؛ مگر اینکه از فلگ --force استفاده کنید.\n";
            exit(1);
        } else {
            echo "⚠️  آیا از حذف فیچر '{$name}' مطمئن هستید؟ (yes/no): ";
            $confirm = trim(fgets(STDIN) ?: '');
        }
        
        if (strtolower($confirm) !== 'yes') {
            echo "❌ عملیات لغو شد.\n";
            return;
        }
        
        if ($this->featureService->delete($name)) {
            echo "✅ فیچر '{$name}' با موفقیت حذف شد.\n";
        } else {
            echo "❌ خطا در حذف فیچر یا فیچر یافت نشد.\n";
            exit(1);
        }
    }
    
    /**
     * تنظیم درصد Rollout
     */
    public function rollout(string $name, int $percentage): void
    {
        if ($percentage < 0 || $percentage > 100) {
            echo "❌ درصد باید بین 0 تا 100 باشد.\n";
            exit(1);
        }
        
        if ($this->featureService->update($name, ['enabled_percentage' => $percentage])) {
            echo "✅ درصد rollout فیچر '{$name}' به {$percentage}% تغییر کرد.\n";
        } else {
            echo "❌ خطا در تغییر rollout.\n";
            exit(1);
        }
    }
    
    /**
     * زمان‌بندی فیچر
     */
    public function schedule(string $name, string $from, string $until): void
    {
        // بررسی اعتبار تاریخ‌ها
        $fromTime = strtotime($from);
        $untilTime = strtotime($until);
        
        if ($fromTime === false || $untilTime === false) {
            echo "❌ خطا: فرمت زمان ارسال شده نامعتبر است.\n";
            exit(1);
        }
        
        if ($untilTime <= $fromTime) {
            echo "❌ خطا: زمان پایان زمان‌بندی باید بعد از زمان شروع باشد.\n";
            exit(1);
        }
        
        try {
            $this->featureService->update($name, [
                'enabled_from' => date('Y-m-d H:i:s', $fromTime),
                'enabled_until' => date('Y-m-d H:i:s', $untilTime),
            ]);
            
            echo "✅ فیچر '{$name}' برای بازه زمانی زیر زمان‌بندی شد:\n";
            echo "   از: " . date('Y-m-d H:i:s', $fromTime) . "\n";
            echo "   تا: " . date('Y-m-d H:i:s', $untilTime) . "\n";
        } catch (\Exception $e) {
            echo "❌ خطا: {$e->getMessage()}\n";
            exit(1);
        }
    }
    
    /**
     * نمایش تاریخچه تغییرات
     */
    public function history(string $name, int $limit = 20): void
    {
        $history = $this->featureService->getHistory($name, $limit);
        
        if (empty($history)) {
            echo "هیچ تاریخچه‌ای برای '{$name}' یافت نشد.\n";
            return;
        }
        
        echo "\n📜 History for '{$name}' (Last {$limit} changes):\n";
        echo "─────────────────────────────────────────────────────────────\n";
        
        foreach ($history as $entry) {
            echo "[{$entry->changed_at}] ";
            echo "{$entry->action}: {$entry->field_changed} ";
            echo "({$entry->old_value} → {$entry->new_value})\n";
        }
        
        echo "\n";
    }
    
    /**
     * پاکسازی Cache
     */
    public function clearCache(): void
    {
        $count = $this->featureService->getCacheCount();
        if ($count === 0) {
            echo "ℹ️ کش خالی است.\n";
            return;
        }
        
        echo "⚠️ این عمل {$count} رکورد کش را حذف میکند. تأیید کنید (yes/no): ";
        $confirm = trim(fgets(STDIN) ?: '');
        
        if (strtolower($confirm) !== 'yes') {
            echo "❌ عملیات لغو شد.\n";
            return;
        }
        
        $this->featureService->clearCache();
        echo "✅ Cache فیچرها پاک شد.\n";
    }
    
    /**
     * پاکسازی Metrics قدیمی
     */
    public function cleanupMetrics(int $days = 30): void
    {
        $this->featureService->cleanupMetrics($days);
        echo "✅ Metrics قدیمی‌تر از {$days} روز پاک شدند.\n";
    }
    /**
     * اجرای دستورات
     */
    public function run(array $argv): void
    {
        $action = $argv[1] ?? 'help';
        
        switch ($action) {
            case 'feature:list':
                $this->list();
                break;
                
            case 'feature:enable':
                $name = $argv[2] ?? null;
                if (!$name) {
                    echo "Usage: php cli.php feature:enable <name>\n";
                    exit(1);
                }
                $this->enable($name);
                break;
                
            case 'feature:disable':
                $name = $argv[2] ?? null;
                if (!$name) {
                    echo "Usage: php cli.php feature:disable <name>\n";
                    exit(1);
                }
                $this->disable($name);
                break;
                
            case 'feature:status':
                $name = $argv[2] ?? null;
                if (!$name) {
                    echo "Usage: php cli.php feature:status <name>\n";
                    exit(1);
                }
                $this->status($name);
                break;
                
            case 'feature:create':
                $name = $argv[2] ?? null;
                $desc = $argv[3] ?? null;
                if (!$name || !$desc) {
                    echo "Usage: php cli.php feature:create <name> <description>\n";
                    exit(1);
                }
                $this->create($name, $desc);
                break;
                
            case 'feature:delete':
                $force = in_array('--force', $argv, true);
                // فیلتر کردن فلگ --force از آرگومان‌ها برای استخراج صحیح نام فیچر
                $argsWithoutFlags = array_values(array_filter($argv, fn($v) => $v !== '--force'));
                $name = $argsWithoutFlags[2] ?? null;
                
                if (!$name) {
                    echo "Usage: php cli.php feature:delete <name> [--force]\n";
                    exit(1);
                }
                $this->delete($name, $force);
                break;
                
            case 'feature:rollout':
                $name = $argv[2] ?? null;
                $percentage = $argv[3] ?? null;
                if (!$name || $percentage === null) {
                    echo "Usage: php cli.php feature:rollout <name> <percentage>\n";
                    exit(1);
                }
                $this->rollout($name, (int)$percentage);
                break;
                
            case 'feature:schedule':
                $name = $argv[2] ?? null;
                $from = $argv[3] ?? null;
                $until = $argv[4] ?? null;
                if (!$name || !$from || !$until) {
                    echo "Usage: php cli.php feature:schedule <name> <from> <until>\n";
                    exit(1);
                }
                $this->schedule($name, $from, $until);
                break;
                
            case 'feature:history':
                $name = $argv[2] ?? null;
                $limit = $argv[3] ?? 20;
                if (!$name) {
                    echo "Usage: php cli.php feature:history <name> [limit]\n";
                    exit(1);
                }
                $this->history($name, (int)$limit);
                break;
                
            case 'feature:clear-cache':
                $this->clearCache();
                break;
                
            case 'feature:cleanup-metrics':
                $days = $argv[2] ?? 30;
                $this->cleanupMetrics((int)$days);
                break;
                
            default:
                echo "Feature Flag Management Commands:\n";
                echo "  feature:list                           - List all features\n";
                echo "  feature:enable <name>                  - Enable a feature\n";
                echo "  feature:disable <name>                 - Disable a feature\n";
                echo "  feature:status <name>                  - Show feature status\n";
                echo "  feature:create <name> <description>    - Create new feature\n";
                echo "  feature:delete <name>                  - Delete a feature\n";
                echo "  feature:rollout <name> <percentage>    - Set rollout percentage\n";
                echo "  feature:schedule <name> <from> <until> - Schedule feature\n";
                echo "  feature:history <name> [limit]         - Show change history\n";
                echo "  feature:clear-cache                    - Clear feature cache\n";
                echo "  feature:cleanup-metrics [days]         - Clean old metrics\n";
                break;
        }
    }
}
