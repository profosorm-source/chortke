-- Create scheduled_payments table if it doesn't exist
CREATE TABLE IF NOT EXISTS `scheduled_payments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(18,4) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'irt',
    `frequency` VARCHAR(50) NOT NULL DEFAULT 'one_time',
    `next_run_at` DATETIME NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'active',
    `description` VARCHAR(255) NULL,
    `metadata` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add composite index for performance optimization
ALTER TABLE `scheduled_payments` ADD INDEX IF NOT EXISTS `idx_sp_status_next_run` (`status`, `next_run_at`);
