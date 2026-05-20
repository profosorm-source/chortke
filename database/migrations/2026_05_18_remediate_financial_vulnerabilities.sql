-- ============================================================
-- Migration: Remediate Financial Vulnerabilities and Hardening
-- Date: 2026-05-18
-- ============================================================

-- Create ledger_entries table if it does not exist (BUG-17, BUG-21)
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

-- Ensure external_id exists in transactions table
ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `external_id` VARCHAR(128) NULL;

-- Drop triggers if they exist
DROP TRIGGER IF EXISTS `prevent_ledger_entries_update`;
DROP TRIGGER IF EXISTS `prevent_ledger_entries_delete`;

-- Drop check constraints if they exist
ALTER TABLE `wallets` DROP CONSTRAINT IF EXISTS `chk_balance_irt_non_negative`;
ALTER TABLE `wallets` DROP CONSTRAINT IF EXISTS `chk_balance_usdt_non_negative`;
ALTER TABLE `ledger_entries` DROP CONSTRAINT IF EXISTS `chk_debit_credit_mutually_exclusive`;

-- Drop indexes if they exist
ALTER TABLE `bank_cards` DROP INDEX IF EXISTS `uq_active_card_number`;
ALTER TABLE `withdrawals` DROP INDEX IF EXISTS `idx_withdrawals_status_created`;
ALTER TABLE `transactions` DROP INDEX IF EXISTS `idx_transactions_external_id`;
ALTER TABLE `transactions` DROP INDEX IF EXISTS `idx_transactions_gateway_tx_id`;
ALTER TABLE `manual_deposits` DROP INDEX IF EXISTS `idx_manual_deposits_status`;

-- Drop column if it exists
ALTER TABLE `bank_cards` DROP COLUMN IF EXISTS `active_card_number`;

-- 1. Fix for BUG-13: Make bank_cards unique for active cards while allowing duplicates for soft-deleted cards
ALTER TABLE `bank_cards` ADD COLUMN `active_card_number` VARCHAR(255) GENERATED ALWAYS AS (
    CASE WHEN `deleted_at` IS NULL THEN `card_number` ELSE NULL END
) STORED;

ALTER TABLE `bank_cards` ADD UNIQUE INDEX `uq_active_card_number` (`active_card_number`);

-- 2. Fix for BUG-15: Database Performance Indexes
ALTER TABLE `withdrawals` ADD INDEX `idx_withdrawals_status_created` (`status`, `created_at`);
ALTER TABLE `transactions` ADD INDEX `idx_transactions_external_id` (`external_id`);
ALTER TABLE `transactions` ADD INDEX `idx_transactions_gateway_tx_id` (`gateway_transaction_id`);
ALTER TABLE `manual_deposits` ADD INDEX `idx_manual_deposits_status` (`status`);

-- 3. Fix for BUG-16: Enforce non-negative wallet balances at database level
ALTER TABLE `wallets` ADD CONSTRAINT `chk_balance_irt_non_negative` CHECK (`balance_irt` >= 0);
ALTER TABLE `wallets` ADD CONSTRAINT `chk_balance_usdt_non_negative` CHECK (`balance_usdt` >= 0);

-- 4. Fix for BUG-17: Enforce mutually exclusive debit/credit ledger entries
ALTER TABLE `ledger_entries` ADD CONSTRAINT `chk_debit_credit_mutually_exclusive` CHECK (
    (`debit` > 0 AND `credit` = 0) OR (`debit` = 0 AND `credit` > 0)
);

-- 5. Fix for BUG-21: Make ledger entries strictly immutable using triggers
CREATE TRIGGER `prevent_ledger_entries_update`
BEFORE UPDATE ON `ledger_entries`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable and cannot be updated.';
END;

CREATE TRIGGER `prevent_ledger_entries_delete`
BEFORE DELETE ON `ledger_entries`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable and cannot be deleted.';
END;
