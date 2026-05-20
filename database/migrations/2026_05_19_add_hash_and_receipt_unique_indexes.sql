-- ============================================================
-- Migration: Add secure card hashing duplicate checks and unique constraints
-- Date: 2026-05-19
-- ============================================================

-- 1. Add card_hash column to bank_cards
ALTER TABLE `bank_cards` ADD COLUMN IF NOT EXISTS `card_hash` VARCHAR(64) NULL;

-- 2. Drop old active_card_number constraints and column
ALTER TABLE `bank_cards` DROP INDEX IF EXISTS `uq_active_card_number`;
ALTER TABLE `bank_cards` DROP COLUMN IF EXISTS `active_card_number`;

-- 3. Populate card_hash for existing cards
-- Since existing card numbers were encrypted deterministically using AES-256-CBC,
-- we'll set card_hash to a SHA256 of the card_number ciphertext or similar if decryption is not direct in SQL.
-- But since it is safer, we'll populate card_hash during registration, and for existing ones we can just seed with card_number ciphertext's SHA256.
UPDATE `bank_cards` SET `card_hash` = SHA2(card_number, 256) WHERE `card_hash` IS NULL;

-- 4. Re-create active_card_number as generated column on card_hash to enforce deterministic uniqueness
ALTER TABLE `bank_cards` ADD COLUMN `active_card_number` VARCHAR(255) GENERATED ALWAYS AS (
    CASE WHEN `deleted_at` IS NULL THEN `card_hash` ELSE NULL END
) STORED;

ALTER TABLE `bank_cards` ADD UNIQUE INDEX `uq_active_card_number` (`active_card_number`);

-- 5. Add unique index for manual_deposits.receipt_hash to block concurrent duplicates
ALTER TABLE `manual_deposits` ADD UNIQUE INDEX IF NOT EXISTS `uq_receipt_hash` (`receipt_hash`);
