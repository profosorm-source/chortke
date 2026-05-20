-- ═══════════════════════════════════════════════════════════════════
-- Database Recovery: Restore corrupted wallets table and apply migrations
-- تاریخ: 2026-04-27
-- ═══════════════════════════════════════════════════════════════════

-- Drop corrupted wallets table if it exists in metadata
DROP TABLE IF EXISTS `wallets`;

-- Recreate wallets table with current schema
CREATE TABLE `wallets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `balance_irt` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `balance_usdt` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `locked_irt` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `locked_usdt` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `last_withdrawal_at` timestamp NULL DEFAULT NULL,
  `is_frozen` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`),
  KEY `idx_wallet_is_frozen` (`is_frozen`),
  CONSTRAINT `wallets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User wallet balances and locked funds for withdrawals';

-- Create ledger_entries table for double-entry accounting
CREATE TABLE IF NOT EXISTS `ledger_entries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` CHAR(36) NOT NULL,
    `account` VARCHAR(100) NOT NULL,
    `debit` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
    `credit` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'irt',
    `description` VARCHAR(500) NULL,
    `metadata` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_transaction_id` (`transaction_id`),
    INDEX `idx_account` (`account`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Ledger entries for wallet and scheduled payment reconciliation';

-- Create scheduled_payments table for recurring payments
CREATE TABLE IF NOT EXISTS `scheduled_payments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(18,4) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'irt',
    `frequency` VARCHAR(50) NOT NULL DEFAULT 'one_time' COMMENT 'daily, weekly, monthly, one_time',
    `next_run_at` DATETIME NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'active',
    `description` VARCHAR(255) NULL,
    `metadata` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_next_run_at` (`next_run_at`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Scheduled wallet payments and recurring charges';

-- Add necessary indexes to transactions table
ALTER TABLE `transactions`
ADD INDEX IF NOT EXISTS `idx_user_idempotency` (`user_id`, `idempotency_key`),
ADD INDEX IF NOT EXISTS `idx_transaction_id` (`transaction_id`);

-- ═══════════════════════════════════════════════════════════════════
-- Recovery Complete
-- ═══════════════════════════════════════════════════════════════════
