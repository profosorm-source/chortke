-- ============================================================
-- Migration: Remediate Financial Issues and Hardening
-- Date: 2026-05-20
-- ============================================================

-- CRIT-01: Add uq_wallet_user unique index to wallets
ALTER TABLE `wallets` DROP INDEX IF EXISTS `uq_wallet_user`;
ALTER TABLE `wallets` ADD UNIQUE KEY `uq_wallet_user` (`user_id`);

-- CRIT-02: Update DB columns to DECIMAL(20,8) and DECIMAL(20,4)
ALTER TABLE `wallets` MODIFY COLUMN `balance_irt` DECIMAL(20,4) NOT NULL DEFAULT 0.0000;
ALTER TABLE `wallets` MODIFY COLUMN `locked_irt` DECIMAL(20,4) NOT NULL DEFAULT 0.0000;
ALTER TABLE `wallets` MODIFY COLUMN `balance_usdt` DECIMAL(20,8) NOT NULL DEFAULT 0.00000000;
ALTER TABLE `wallets` MODIFY COLUMN `locked_usdt` DECIMAL(20,8) NOT NULL DEFAULT 0.00000000;

-- HIGH-01: Non-negative check constraints on locked balances
ALTER TABLE `wallets` DROP CONSTRAINT IF EXISTS `chk_locked_irt_non_negative`;
ALTER TABLE `wallets` DROP CONSTRAINT IF EXISTS `chk_locked_usdt_non_negative`;
ALTER TABLE `wallets` ADD CONSTRAINT `chk_locked_irt_non_negative` CHECK (`locked_irt` >= 0);
ALTER TABLE `wallets` ADD CONSTRAINT `chk_locked_usdt_non_negative` CHECK (`locked_usdt` >= 0);

-- MED-08: Unique tracking_code on manual_deposits
ALTER TABLE `manual_deposits` DROP INDEX IF EXISTS `uq_tracking_code`;
ALTER TABLE `manual_deposits` ADD UNIQUE KEY `uq_tracking_code` (`tracking_code`);

-- MED-02: Add running balance columns to ledger_entries and update debit/credit column precision
DROP TRIGGER IF EXISTS `prevent_ledger_entries_update`;
DROP TRIGGER IF EXISTS `prevent_ledger_entries_delete`;

ALTER TABLE `ledger_entries` MODIFY COLUMN `debit` DECIMAL(20,8) NOT NULL DEFAULT 0.00000000;
ALTER TABLE `ledger_entries` MODIFY COLUMN `credit` DECIMAL(20,8) NOT NULL DEFAULT 0.00000000;

ALTER TABLE `ledger_entries` ADD COLUMN IF NOT EXISTS `running_balance` DECIMAL(20,8) NOT NULL DEFAULT 0.00000000;
ALTER TABLE `ledger_entries` ADD COLUMN IF NOT EXISTS `balance_before` DECIMAL(20,8) NULL;
ALTER TABLE `ledger_entries` ADD COLUMN IF NOT EXISTS `balance_after` DECIMAL(20,8) NULL;

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

-- CRIT-04: Add uq_user_idempotency unique index to withdrawals
ALTER TABLE `withdrawals` DROP INDEX IF EXISTS `uq_user_idempotency`;
ALTER TABLE `withdrawals` ADD UNIQUE KEY `uq_user_idempotency` (`user_id`, `idempotency_key`);

-- HIGH-02: Add uq_escrow_order unique index to escrow_transactions
ALTER TABLE `escrow_transactions` DROP INDEX IF EXISTS `uq_escrow_order`;
ALTER TABLE `escrow_transactions` ADD UNIQUE KEY `uq_escrow_order` (`order_id`, `order_type`);

