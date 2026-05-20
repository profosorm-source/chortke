-- Add signature column to user_level_history
ALTER TABLE `user_level_history` ADD COLUMN `signature` VARCHAR(64) NULL AFTER `ip_address`;
