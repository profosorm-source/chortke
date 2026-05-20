-- جدول کارت‌های بانکی

CREATE TABLE IF NOT EXISTS `bank_cards` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `card_number` VARCHAR(19) NOT NULL COMMENT 'شماره کارت (مشفر شده)',
    `card_holder_name` VARCHAR(100) NULL COMMENT 'نام صاحب کارت',
    `bank_name` VARCHAR(100) NULL COMMENT 'نام بانک',
    `iban` VARCHAR(26) NULL COMMENT 'شماره شبا',
    `status` ENUM('pending', 'verified', 'blocked', 'expired') NOT NULL DEFAULT 'pending' COMMENT 'وضعیت کارت',
    `is_default` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'کارت پیش‌فرض',
    `verified_at` TIMESTAMP NULL,
    `last_used_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- ایندکس‌ها
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_verified_at` (`verified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='جدول کارت‌های بانکی';
