-- ============================================================
-- Migration: Hardening Chortke Financial System and Database Constraints
-- Date: 2026-05-21
-- ============================================================

-- 1. Hardening withdrawal idempotency key globally
ALTER TABLE `withdrawals` DROP INDEX IF EXISTS `uq_user_idempotency`;
ALTER TABLE `withdrawals` DROP INDEX IF EXISTS `uq_withdrawals_idempotency_key`;
ALTER TABLE `withdrawals` ADD UNIQUE KEY `uq_withdrawals_idempotency_key` (`idempotency_key`);

-- 2. Hardening ledger_entries uniqueness composite index
ALTER TABLE `ledger_entries` DROP INDEX IF EXISTS `uq_ledger_entries_leg`;
ALTER TABLE `ledger_entries` ADD UNIQUE KEY `uq_ledger_entries_leg` (`transaction_id`, `account`, `debit`, `credit`, `currency`);
