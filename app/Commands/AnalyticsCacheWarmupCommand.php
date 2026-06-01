<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Analytics\AnalyticsService;
use App\Contracts\LoggerInterface;

/**
 * 🚀 UPG-04: AnalyticsCacheWarmupCommand - پیش‌گرمایش کش‌های آماری سنگین داشبورد
 *
 * استفاده: php cli.php analytics:warm
 */
class AnalyticsCacheWarmupCommand
{
    private AnalyticsService $analytics;
    private LoggerInterface $logger;
    public function __construct(
        AnalyticsService $analytics,
        LoggerInterface $logger
    ) {        $this->analytics = $analytics;
        $this->logger = $logger;
}

    /**
     * اجرای فرایند پیش‌گرمایش
     */
    public function run(array $argv): void
    {
        $this->logger->info('command.analytics_warmup.starting');
        echo "\n\033[1;34m🚀 Starting Analytics Cache Warmup...\033[0m\n";

        try {
            echo "📈 Pre-loading User Metrics...\n";
            $userMetrics = $this->analytics->getUserMetrics();
            echo "   ✓ Loaded " . count($userMetrics) . " user data points.\n";

            echo "💸 Pre-loading Transaction Metrics...\n";
            $txMetrics = $this->analytics->getTransactionMetrics();
            echo "   ✓ Loaded " . count($txMetrics) . " financial data points.\n";

            echo "📋 Pre-loading Task Metrics...\n";
            $taskMetrics = $this->analytics->getTaskMetrics();
            echo "   ✓ Loaded " . count($taskMetrics) . " operational data points.\n";
            
            echo "📅 Pre-loading Daily Registrations (30 days)...\n";
            $registrations = $this->analytics->getDailyRegistrations(30);
            echo "   ✓ Loaded " . count($registrations) . " daily registration records.\n";

            $this->logger->info('command.analytics_warmup.completed', [
                'user_metrics_count' => count($userMetrics),
                'tx_metrics_count' => count($txMetrics),
                'task_metrics_count' => count($taskMetrics)
            ]);
            
            echo "\n\033[1;32m✅ Analytics Cache Warmup Successfully Completed!\033[0m\n\n";
        } catch (\Throwable $e) {
            $this->logger->error('command.analytics_warmup.failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            echo "\n\033[1;31m❌ Analytics Cache Warmup Failed:\033[0m " . $e->getMessage() . "\n\n";
            exit(1);
        }
    }
}
