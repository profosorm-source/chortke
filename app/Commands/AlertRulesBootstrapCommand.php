<?php

declare(strict_types=1);

namespace App\Commands;

use Core\Command;
use Core\Database;
use Core\Logger;

/**
 * 🚀 AlertRulesBootstrapCommand - رجسٹر کردن DLQ Alert Rule
 * 
 * این کمند DLQ alert rule را درج می‌کند:
 * - failed_jobs > 50 => CRITICAL alert
 * - failed_jobs > 20 => WARNING alert
 */
class AlertRulesBootstrapCommand extends Command
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function handle(): void
    {
        $this->registerDLQAlertRules();
        $this->line('✅ DLQ Alert Rules Registered');
    }

    private function registerDLQAlertRules(): void
    {
        // چک کنید آیا rule وجود دارد
        $existing = $this->db->fetchColumn(
            "SELECT id FROM alert_rules WHERE rule_name = ?",
            ['DLQ Failed Jobs Critical']
        );

        if ($existing) {
            return; // Rule تالاً موجود است
        }

        // Critical Rule: failed_jobs > 50
        $this->db->table('alert_rules')->insert([
            'rule_name' => 'DLQ Failed Jobs Critical',
            'rule_type' => 'queue_dlq',
            'condition' => json_encode([
                'metric' => 'failed_jobs',
                'operator' => '>',
            ], JSON_UNESCAPED_UNICODE),
            'threshold' => 50,
            'severity' => 'critical',
            'time_window' => 5, // 5 minutes
            'is_active' => 1,
            'description' => 'تعداد failed jobs بیش از 50 است',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Warning Rule: failed_jobs > 20
        $this->db->table('alert_rules')->insert([
            'rule_name' => 'DLQ Failed Jobs Warning',
            'rule_type' => 'queue_dlq',
            'condition' => json_encode([
                'metric' => 'failed_jobs',
                'operator' => '>',
            ], JSON_UNESCAPED_UNICODE),
            'threshold' => 20,
            'severity' => 'warning',
            'time_window' => 5,
            'is_active' => 1,
            'description' => 'تعداد failed jobs بیش از 20 است',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('alert_rules.dlq_bootstrap', [
            'message' => 'DLQ alert rules registered',
            'critical_threshold' => 50,
            'warning_threshold' => 20,
        ]);
    }
}
