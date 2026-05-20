-- Migration: Create social_accounts table (April 28, 2026)

CREATE TABLE IF NOT EXISTS `social_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  
  -- صارف کی شناخت
  `user_id` bigint unsigned NOT NULL,
  
  -- OAuth Provider
  `provider` enum('google', 'facebook') NOT NULL DEFAULT 'google',
  `provider_id` varchar(255) NOT NULL COMMENT 'Provider میں صارف کی ID',
  `provider_email` varchar(255) COLLATE utf8mb4_unicode_ci,
  `provider_name` varchar(255) COLLATE utf8mb4_unicode_ci,
  
  -- تمام provider data (JSON میں)
  `data` json,
  
  -- Timestamps
  `linked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- Indexes
  UNIQUE KEY `uq_provider_id` (`provider`, `provider_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_social_accounts_user_id` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alter users table to support social-only accounts (if not already done)
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `oauth_only` tinyint(1) DEFAULT 0 AFTER `password`,
MODIFY COLUMN `password` varchar(255) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;

COMMIT;
