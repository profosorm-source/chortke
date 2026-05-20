-- Migration: Add missing performance indexes (NEW-5)
ALTER TABLE `user_level_history` ADD INDEX IF NOT EXISTS `idx_ulh_user_level` (`user_id`, `to_level`);
ALTER TABLE `user_level_history` ADD INDEX IF NOT EXISTS `idx_ulh_user_created` (`user_id`, `created_at`);
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_role_status` (`role`, `status`);
ALTER TABLE `user_score_events` ADD INDEX IF NOT EXISTS `idx_use_user_domain` (`user_id`, `domain`);
