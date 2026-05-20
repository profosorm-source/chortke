-- Migration: Create log tables for LogService
-- Created: 2026-05-03
-- Purpose: Support LogService with proper database tables

-- System Logs Table (errors, warnings, info)
CREATE TABLE IF NOT EXISTS `system_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security Logs Table (security events, attacks, auth)
CREATE TABLE IF NOT EXISTS `security_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Performance Logs Table (metrics, response times)
CREATE TABLE IF NOT EXISTS `performance_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity Logs Table (user actions - if not exists)
CREATE TABLE IF NOT EXISTS `activity_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</content>
<parameter name="filePath">c:\xampp\htdocs\chortke\database\migrations\2026_05_03_create_log_tables.sql