-- Migration: Create sentry_issues and sentry_events tables
-- Created: 2026-04-28

CREATE TABLE IF NOT EXISTS `sentry_issues` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `fingerprint` varchar(255) NOT NULL,
    `level` varchar(50) NOT NULL,
    `title` varchar(255) NOT NULL,
    `culprit` text DEFAULT NULL,
    `first_seen` datetime NOT NULL,
    `last_seen` datetime NOT NULL,
    `count` int(11) NOT NULL DEFAULT 1,
    `environment` varchar(50) NOT NULL,
    `release_version` varchar(100) DEFAULT NULL,
    `status` varchar(50) NOT NULL DEFAULT 'unresolved',
    `metadata` longtext DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `sentry_issues_fingerprint_environment_index` (`fingerprint`, `environment`),
    KEY `sentry_issues_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sentry_events` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `event_id` varchar(64) NOT NULL,
    `issue_id` bigint(20) unsigned NOT NULL,
    `level` varchar(50) NOT NULL,
    `message` longtext NOT NULL,
    `exception_type` varchar(255) DEFAULT NULL,
    `stack_trace` longtext DEFAULT NULL,
    `breadcrumbs` longtext DEFAULT NULL,
    `user_context` longtext DEFAULT NULL,
    `request_context` longtext DEFAULT NULL,
    `device_context` longtext DEFAULT NULL,
    `tags` longtext DEFAULT NULL,
    `extra` longtext DEFAULT NULL,
    `environment` varchar(50) NOT NULL,
    `release_version` varchar(100) DEFAULT NULL,
    `user_id` bigint(20) unsigned DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sentry_events_event_id_unique` (`event_id`),
    KEY `sentry_events_issue_id_index` (`issue_id`),
    KEY `sentry_events_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
