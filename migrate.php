<?php
/**
 * Migration Runner - Execute SQL migrations
 */

try {
    // Database configuration
    $dbConfig = [
        'host' => 'localhost',
        'database' => 'chortke',
        'username' => 'root',
        'password' => '',
    ];
    
    // Connect to database
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password']
    );
    
    // Select database
    $pdo->exec("USE `{$dbConfig['database']}`");
    
    // Statements to execute
    $statements = [
        // System Logs Table
        "CREATE TABLE IF NOT EXISTS `system_logs` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `level` varchar(20) NOT NULL COMMENT 'ERROR, WARNING, INFO, DEBUG',
            `type` varchar(20) NOT NULL DEFAULT 'system',
            `message` text NOT NULL,
            `context` longtext DEFAULT NULL COMMENT 'JSON context data',
            `user_id` bigint(20) unsigned DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `system_logs_level_index` (`level`),
            KEY `system_logs_type_index` (`type`),
            KEY `system_logs_user_id_index` (`user_id`),
            KEY `system_logs_created_at_index` (`created_at`),
            KEY `system_logs_ip_address_index` (`ip_address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Security Logs Table
        "CREATE TABLE IF NOT EXISTS `security_logs` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `level` varchar(20) NOT NULL COMMENT 'EMERGENCY, ALERT, CRITICAL, ERROR, WARNING',
            `type` varchar(20) NOT NULL DEFAULT 'security',
            `message` text NOT NULL,
            `context` longtext DEFAULT NULL COMMENT 'JSON context data',
            `user_id` bigint(20) unsigned DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `security_logs_level_index` (`level`),
            KEY `security_logs_type_index` (`type`),
            KEY `security_logs_user_id_index` (`user_id`),
            KEY `security_logs_created_at_index` (`created_at`),
            KEY `security_logs_ip_address_index` (`ip_address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Performance Logs Table
        "CREATE TABLE IF NOT EXISTS `performance_logs` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `metric` varchar(100) NOT NULL COMMENT 'response_time, memory_usage, db_query_time, etc',
            `value` decimal(10,4) NOT NULL COMMENT 'metric value',
            `context` longtext DEFAULT NULL COMMENT 'JSON context data',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `performance_logs_metric_index` (`metric`),
            KEY `performance_logs_created_at_index` (`created_at`),
            KEY `performance_logs_value_index` (`value`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Activity Logs Table
        "CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned DEFAULT NULL,
            `action` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `model` varchar(100) DEFAULT NULL,
            `model_id` bigint(20) unsigned DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `metadata` longtext DEFAULT NULL COMMENT 'JSON metadata',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `activity_logs_user_id_index` (`user_id`),
            KEY `activity_logs_action_index` (`action`),
            KEY `activity_logs_model_index` (`model`),
            KEY `activity_logs_created_at_index` (`created_at`),
            KEY `activity_logs_deleted_at_index` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    
    $executed = 0;
    foreach ($statements as $statement) {
        if (!empty(trim($statement))) {
            $desc = substr(str_replace(["\n", "\r", "\t"], " ", $statement), 0, 60);
            echo "Executing: {$desc}...\n";
            $pdo->exec($statement);
            $executed++;
        }
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "Total tables created: {$executed}\n";
    
} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
