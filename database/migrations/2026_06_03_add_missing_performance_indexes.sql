-- Migration: Add missing database performance indexes with extreme robustness checks
-- We verify if the target table exists and ensure the index is not already present before executing ALTER TABLE.
-- This prevents crashes if tables do not exist or indexes have already been defined.

-- 1. Index for score_events (entity_type, entity_id, domain)
SET @table_exists := (SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'score_events');
SET @idx_exists := (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'score_events' AND INDEX_NAME = 'idx_score_events_entity_domain');
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE `score_events` ADD INDEX `idx_score_events_entity_domain` (`entity_type`, `entity_id`, `domain`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Index for custom_task_submissions (worker_id, status, created_at)
SET @table_exists := (SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_task_submissions');
SET @idx_exists := (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_task_submissions' AND INDEX_NAME = 'idx_custom_task_subs_worker_status_created');
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE `custom_task_submissions` ADD INDEX `idx_custom_task_subs_worker_status_created` (`worker_id`, `status`, `created_at`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Index for ledger_entries (account, currency, created_at)
SET @table_exists := (SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries');
SET @idx_exists := (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries' AND INDEX_NAME = 'idx_ledger_entries_acct_curr_created');
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE `ledger_entries` ADD INDEX `idx_ledger_entries_acct_curr_created` (`account`, `currency`, `created_at`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Index for notifications (user_id, is_read, created_at)
SET @table_exists := (SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications');
SET @idx_exists := (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_user_read_created');
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE `notifications` ADD INDEX `idx_notifications_user_read_created` (`user_id`, `is_read`, `created_at`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
