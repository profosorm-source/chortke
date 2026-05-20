-- ============================================================
-- Migration: Add Cryptographic Hash Chaining to Ledger Entries
-- Date: 2026-05-23
-- ============================================================

ALTER TABLE `ledger_entries` 
    ADD COLUMN `entry_hash` CHAR(64) NULL COMMENT 'SHA-256 hash of this entry combined with previous entry hash',
    ADD COLUMN `prev_hash` CHAR(64) NULL COMMENT 'SHA-256 hash of the immediately preceding entry';

CREATE INDEX `idx_ledger_entries_entry_hash` ON `ledger_entries` (`entry_hash`);
