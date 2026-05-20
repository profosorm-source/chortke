-- جدول برای ذخیره کلیدهای Idempotency
-- این جدول از اجرای مجدد درخواست‌های مالی جلوگیری می‌کند

CREATE TABLE IF NOT EXISTS `idempotency_keys` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(64) NOT NULL COMMENT 'Idempotency Key (hex)',
    `user_id` BIGINT UNSIGNED NOT NULL,
    `action` VARCHAR(100) NOT NULL COMMENT 'نام عمل (wallet_deposit, withdrawal, etc)',
    `status` ENUM('processing', 'completed', 'failed') NOT NULL DEFAULT 'processing',
    `request_data` JSON COMMENT 'داده‌های درخواست اصلی',
    `result` LONGTEXT COMMENT 'نتیجه JSON',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    
    -- ایندکس‌ها
    UNIQUE KEY `uq_user_action_key` (`user_id`, `action`, `key`),
    KEY `idx_key` (`key`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires_at` (`expires_at`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Idempotency Key Storage - جلوگیری از درخواست‌های تکراری';
