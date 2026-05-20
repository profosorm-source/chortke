-- Database hardening for Crypto Deposits and Intents
-- Preventing double processing, replay attacks, amount collisions, and duplicate submissions

-- 1. Unique index on tx_hash (preventing duplicate processing & replay attacks)
ALTER TABLE `crypto_deposits` DROP INDEX IF EXISTS `idx_tx_hash`;
ALTER TABLE `crypto_deposits` DROP INDEX IF EXISTS `uq_tx_hash`;
ALTER TABLE `crypto_deposits` ADD UNIQUE INDEX `uq_tx_hash` (`tx_hash`);

-- 2. Prevent duplicate pending/manual_review submissions by the same user using a virtual generated column
ALTER TABLE `crypto_deposits` DROP INDEX IF EXISTS `idx_user_pending`;
ALTER TABLE `crypto_deposits` DROP COLUMN IF EXISTS `pending_status`;

ALTER TABLE `crypto_deposits` ADD COLUMN `pending_status` VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN `verification_status` IN ('pending', 'manual_review') THEN `verification_status` ELSE NULL END) VIRTUAL;
ALTER TABLE `crypto_deposits` ADD UNIQUE INDEX `idx_user_pending` (`user_id`, `pending_status`);

-- 3. Indexes for frequent queries
ALTER TABLE `crypto_deposits` DROP INDEX IF EXISTS `idx_status_created`;
CREATE INDEX `idx_status_created` ON `crypto_deposits` (`verification_status`, `created_at`);

ALTER TABLE `crypto_deposits` DROP INDEX IF EXISTS `idx_user_status`;
CREATE INDEX `idx_user_status` ON `crypto_deposits` (`user_id`, `verification_status`);

-- 4. Constraint for amount
ALTER TABLE `crypto_deposits` DROP CONSTRAINT IF EXISTS `chk_amount_positive`;
ALTER TABLE `crypto_deposits` ADD CONSTRAINT `chk_amount_positive` CHECK (`amount` > 0);

-- 5. Intent uniqueness to prevent expected amount collision on open intents using a virtual generated column
ALTER TABLE `crypto_deposit_intents` DROP INDEX IF EXISTS `idx_open_network_amount`;
ALTER TABLE `crypto_deposit_intents` DROP COLUMN IF EXISTS `open_status`;

ALTER TABLE `crypto_deposit_intents` ADD COLUMN `open_status` VARCHAR(10) GENERATED ALWAYS AS (CASE WHEN `status` = 'open' THEN `status` ELSE NULL END) VIRTUAL;
ALTER TABLE `crypto_deposit_intents` ADD UNIQUE INDEX `idx_open_network_amount` (`network`, `expected_amount`, `open_status`);
