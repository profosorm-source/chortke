<?php

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

use App\Models\FeatureFlag;
use Core\Database;
use App\Contracts\LoggerInterface;

class FeatureFlagCommand
{
    private FeatureFlag $model;
    private Database $db;
    private LoggerInterface $logger;
    
    public function __construct(Database $db, LoggerInterface $logger, FeatureFlag $model)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->model = $model;
    }
    
    /**
     * لیست همه فیچرها
     */
    public function list(): void
    {
        $features = $this->model->getAll();
        
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
            $priority = str_pad($feature->priority ?? 0, 17);
            
            echo "│ {$name} │ {$enabled} │ {$rollout} │ {$priority} │\n";
        }
        
        echo "└──────────────────────────────┴──────────┴──────────┴───────────────────┘\n";
        
        $stats = $this->model->getStats();
        echo "\nآمار: {$stats['total']} فیچر | {$stats['enabled']} فعال | {$stats['disabled']} غیرفعال\n\n";
    }
    
    /**
     * فعال کردن فیچر
     */
    public function enable(string $name): void
    {
        $feature = $this->model->findByName($name);
        
        if (!$feature) {
            echo "❌ فیچر '{$name}' یافت نشد.\n";
            exit(1);
        }
        
        if ($feature->enabled) {
            echo "ℹ️  فیچر '{$name}' از قبل فعال است.\n";
            return;
        }
        
        if ($this->model->update($name, ['enabled' => true])) {
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
        $feature = $this->model->findByName($name);
        
        if (!$feature) {
            echo "❌ فیچر '{$name}' یافت نشد.\n";
            exit(1);
        }
        
        if (!$feature->enabled) {
            echo "ℹ️  فیچر '{$name}' از قبل غیرفعال است.\n";
            return;
        }
        
        if ($this->model->update($name, ['enabled' => false])) {
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
        $feature = $this->model->findByName($name);
        
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
        $metrics = $this->model->getMetrics($name, 24);
        
        if (!empty($metrics)) {
            echo "📊 Metrics (Last 24 hours):\n";
            foreach ($metrics as $metric) {
                echo "   - Total checks: {$metric->total_checks}\n";
                echo "   - Allowed: {$metric->allowed_count}\n";
                echo "   - Denied: {$metric->denied_count}\n";
                echo "   - Avg response: " . round($metric->avg_response_time, 2) . "ms\n";
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
            if ($this->model->create([
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
    public function delete(string $name): void
    {
        echo "⚠️  آیا از حذف فیچر '{$name}' مطمئن هستید؟ (yes/no): ";
        $confirm = trim(fgets(STDIN));
        
        if (strtolower($confirm) !== 'yes') {
            echo "❌ عملیات لغو شد.\n";
            return;
        }
        
        if ($this->model->delete($name)) {
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
        
        if ($this->model->update($name, ['enabled_percentage' => $percentage])) {
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
        try {
            $this->model->update($name, [
                'enabled_from' => $from,
                'enabled_until' => $until,
            ]);
            
            echo "✅ فیچر '{$name}' برای بازه زمانی زیر زمان‌بندی شد:\n";
            echo "   از: {$from}\n";
            echo "   تا: {$until}\n";
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
        $history = $this->model->getHistory($name, $limit);
        
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
        $this->db->query("TRUNCATE TABLE feature_flag_cache");
        echo "✅ Cache فیچرها پاک شد.\n";
    }
    
    /**
     * پاکسازی Metrics قدیمی
     */
    public function cleanupMetrics(int $days = 30): void
    {
        $sql = "CALL sp_cleanup_feature_metrics(?)";
        $this->db->query($sql, [$days]);
        
        echo "✅ Metrics قدیمی‌تر از {$days} روز پاک شدند.\n";
    }
}

// اجرای Command
if (php_sapi_name() === 'cli') {
    $command = \Core\Container::getInstance()->make(FeatureFlagCommand::class);
    
    $action = $argv[1] ?? 'help';
    
    switch ($action) {
        case 'feature:list':
            $command->list();
            break;
            
        case 'feature:enable':
            $name = $argv[2] ?? null;
            if (!$name) {
                echo "Usage: php cli.php feature:enable <name>\n";
                exit(1);
            }
            $command->enable($name);
            break;
            
        case 'feature:disable':
            $name = $argv[2] ?? null;
            if (!$name) {
                echo "Usage: php cli.php feature:disable <name>\n";
                exit(1);
            }
            $command->disable($name);
            break;
            
        case 'feature:status':
            $name = $argv[2] ?? null;
            if (!$name) {
                echo "Usage: php cli.php feature:status <name>\n";
                exit(1);
            }
            $command->status($name);
            break;
            
        case 'feature:create':
            $name = $argv[2] ?? null;
            $desc = $argv[3] ?? null;
            if (!$name || !$desc) {
                echo "Usage: php cli.php feature:create <name> <description>\n";
                exit(1);
            }
            $command->create($name, $desc);
            break;
            
        case 'feature:delete':
            $name = $argv[2] ?? null;
            if (!$name) {
                echo "Usage: php cli.php feature:delete <name>\n";
                exit(1);
            }
            $command->delete($name);
            break;
            
        case 'feature:rollout':
            $name = $argv[2] ?? null;
            $percentage = $argv[3] ?? null;
            if (!$name || $percentage === null) {
                echo "Usage: php cli.php feature:rollout <name> <percentage>\n";
                exit(1);
            }
            $command->rollout($name, (int)$percentage);
            break;
            
        case 'feature:schedule':
            $name = $argv[2] ?? null;
            $from = $argv[3] ?? null;
            $until = $argv[4] ?? null;
            if (!$name || !$from || !$until) {
                echo "Usage: php cli.php feature:schedule <name> <from> <until>\n";
                exit(1);
            }
            $command->schedule($name, $from, $until);
            break;
            
        case 'feature:history':
            $name = $argv[2] ?? null;
            $limit = $argv[3] ?? 20;
            if (!$name) {
                echo "Usage: php cli.php feature:history <name> [limit]\n";
                exit(1);
            }
            $command->history($name, (int)$limit);
            break;
            
        case 'feature:clear-cache':
            $command->clearCache();
            break;
            
        case 'feature:cleanup-metrics':
            $days = $argv[2] ?? 30;
            $command->cleanupMetrics((int)$days);
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
