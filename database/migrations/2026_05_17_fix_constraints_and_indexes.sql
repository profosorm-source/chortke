-- ============================================================
-- Migration: Fix Constraints and Indexes (BUG-11, BUG-12, BUG-13)
-- Date: 2026-05-17
-- ============================================================

-- 1. Fix for BUG-11: Add Foreign Key constraints for card_id to prevent orphan states
ALTER TABLE `manual_deposits`
  ADD CONSTRAINT `fk_manual_deposits_card_id`
  FOREIGN KEY (`card_id`) REFERENCES `bank_cards` (`id`) ON DELETE RESTRICT;

ALTER TABLE `withdrawals`
  ADD CONSTRAINT `fk_withdrawals_card_id`
  FOREIGN KEY (`card_id`) REFERENCES `bank_cards` (`id`) ON DELETE RESTRICT;

-- 2. Fix for BUG-12: Enforce Database-level uniqueness on transaction idempotency_key
-- Note: If unique constraint already exists, this guarantees/enforces it safely
ALTER TABLE `transactions`
  ADD UNIQUE INDEX `uq_transactions_idempotency_key` (`idempotency_key`);

-- 3. Fix for BUG-13: Make manual_deposits tracking_code globally unique
ALTER TABLE `manual_deposits`
  ADD UNIQUE INDEX `uq_manual_deposits_tracking_code` (`tracking_code`);
