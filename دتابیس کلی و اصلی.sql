-- ============================================================
-- فایل SQL اسکیما دیتابیس پروژه چرتکه
-- استخراج‌شده از کدهای واقعی مدل‌ها (نه فایل SQL قدیمی)
-- تاریخ: 2026-05-12
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================
-- 1. جدول کاربران (users)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name`                 VARCHAR(100) NOT NULL,
    `email`                     VARCHAR(191) UNIQUE,
    `mobile`                    VARCHAR(20) UNIQUE,
    `password`                  VARCHAR(255) NOT NULL,
    `role`                      VARCHAR(30) NOT NULL DEFAULT 'user',
    `status`                    VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|suspended|banned',
    `referral_code`             VARCHAR(20) UNIQUE,
    `referred_by`               INT UNSIGNED NULL,
    `tier_level`                VARCHAR(20) DEFAULT 'silver' COMMENT 'silver|gold|vip',
    `email_verified_at`         DATETIME NULL,
    `email_verification_token`  VARCHAR(128) NULL,
    `phone`                     VARCHAR(20) NULL,
    `avatar`                    VARCHAR(255) NULL,
    `bio`                       TEXT NULL,
    `last_login`                DATETIME NULL,
    `last_ip`                   VARCHAR(45) NULL,
    `last_user_agent`           VARCHAR(512) NULL,
    `fraud_score`               INT DEFAULT 0,
    `fraud_score_updated_at`    DATETIME NULL,
    `is_blacklisted`            TINYINT(1) DEFAULT 0,
    `reputation_score`          INT DEFAULT 0,
    `two_factor_enabled`        TINYINT(1) DEFAULT 0,
    `two_factor_secret`         VARCHAR(64) NULL,
    `kyc_status`                VARCHAR(20) DEFAULT 'not_submitted' COMMENT 'not_submitted|pending|verified|rejected',
    `deleted_at`                DATETIME NULL,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_mobile` (`mobile`),
    INDEX `idx_referral_code` (`referral_code`),
    INDEX `idx_referred_by` (`referred_by`),
    INDEX `idx_status` (`status`),
    INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. تنظیمات کاربران (user_settings)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_settings` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`       INT UNSIGNED NOT NULL,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT NULL,
    `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_setting` (`user_id`, `setting_key`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. کیف پول (wallets)
-- ============================================================
CREATE TABLE IF NOT EXISTS `wallets` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT UNSIGNED NOT NULL UNIQUE,
    `balance_irt`         DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    `locked_irt`          DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    `balance_usdt`        DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    `locked_usdt`         DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    `is_frozen`           TINYINT(1) NOT NULL DEFAULT 0,
    `last_withdrawal_at`  DATETIME NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. تراکنش‌ها (transactions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `transactions` (
    `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id`         VARCHAR(64) NOT NULL UNIQUE COMMENT 'UUID',
    `user_id`                INT UNSIGNED NOT NULL,
    `type`                   VARCHAR(30) NOT NULL COMMENT 'deposit|withdraw|reward|transfer|...',
    `amount`                 DECIMAL(20,2) NOT NULL,
    `currency`               VARCHAR(10) NOT NULL DEFAULT 'irt' COMMENT 'irt|usdt',
    `status`                 VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|completed|failed|cancelled',
    `description`            VARCHAR(500) NULL,
    `from_user_id`           INT UNSIGNED NULL,
    `to_user_id`             INT UNSIGNED NULL,
    `external_id`            VARCHAR(128) NULL,
    `gateway_transaction_id` VARCHAR(128) NULL,
    `idempotency_key`        VARCHAR(128) NULL UNIQUE,
    `ip_address`             VARCHAR(45) NULL,
    `device_fingerprint`     VARCHAR(128) NULL,
    `metadata`               JSON NULL,
    `completed_at`           DATETIME NULL,
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_from_user_id` (`from_user_id`),
    INDEX `idx_to_user_id` (`to_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. رویدادهای تراکنش (transaction_events) - Event Sourcing
-- ============================================================
CREATE TABLE IF NOT EXISTS `transaction_events` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id`  VARCHAR(64) NOT NULL,
    `event_type`      VARCHAR(50) NOT NULL DEFAULT 'status_change',
    `previous_status` VARCHAR(20) NULL,
    `new_status`      VARCHAR(20) NOT NULL,
    `reason`          VARCHAR(500) NULL,
    `changed_by`      INT UNSIGNED NULL COMMENT 'NULL = system',
    `metadata`        JSON NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_transaction_id` (`transaction_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. کارت‌های بانکی (bank_cards)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bank_cards` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT UNSIGNED NOT NULL,
    `card_number`      VARCHAR(20) NOT NULL,
    `bank_name`        VARCHAR(100) NULL,
    `sheba`            VARCHAR(30) NULL,
    `owner_name`       VARCHAR(100) NULL,
    `is_default`       TINYINT(1) DEFAULT 0,
    `status`           VARCHAR(20) DEFAULT 'pending' COMMENT 'pending|verified|rejected',
    `rejection_reason` TEXT NULL,
    `reviewed_by`      INT UNSIGNED NULL,
    `verified_at`      DATETIME NULL,
    `deleted_at`       DATETIME NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. واریز دستی (manual_deposits)
-- ============================================================
CREATE TABLE IF NOT EXISTS `manual_deposits` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT UNSIGNED NOT NULL,
    `card_id`          INT UNSIGNED NULL,
    `amount`           DECIMAL(20,2) NOT NULL,
    `currency`         VARCHAR(10) NOT NULL DEFAULT 'irt',
    `status`           VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|under_review|approved|rejected',
    `receipt_image`    VARCHAR(255) NULL,
    `transaction_id`   VARCHAR(64) NULL,
    `rejection_reason` TEXT NULL,
    `reviewed_by`      INT UNSIGNED NULL,
    `reviewed_at`      DATETIME NULL,
    `note`             TEXT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_card_id` (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. برداشت‌ها (withdrawals)
-- ============================================================
CREATE TABLE IF NOT EXISTS `withdrawals` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT UNSIGNED NOT NULL,
    `card_id`          INT UNSIGNED NULL,
    `amount`           DECIMAL(20,2) NOT NULL,
    `currency`         VARCHAR(10) NOT NULL DEFAULT 'irt',
    `status`           VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|completed|rejected',
    `transaction_id`   VARCHAR(64) NULL,
    `rejection_reason` TEXT NULL,
    `processed_by`     INT UNSIGNED NULL,
    `processed_at`     DATETIME NULL,
    `note`             TEXT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_card_id` (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. واریز کریپتو (crypto_deposits)
-- ============================================================
CREATE TABLE IF NOT EXISTS `crypto_deposits` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NOT NULL,
    `intent_id`      INT UNSIGNED NULL,
    `network`        VARCHAR(30) NOT NULL COMMENT 'TRC20|ERC20|BEP20',
    `wallet_address` VARCHAR(128) NOT NULL,
    `amount`         DECIMAL(20,8) NULL,
    `tx_hash`        VARCHAR(128) NULL UNIQUE,
    `confirmations`  INT DEFAULT 0,
    `status`         VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|confirming|confirmed|failed',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_tx_hash` (`tx_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. اینتنت واریز کریپتو (crypto_deposit_intents)
-- ============================================================
CREATE TABLE IF NOT EXISTS `crypto_deposit_intents` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NOT NULL,
    `network`        VARCHAR(30) NOT NULL,
    `wallet_address` VARCHAR(128) NOT NULL,
    `expected_amount`DECIMAL(20,8) NULL,
    `status`         VARCHAR(20) NOT NULL DEFAULT 'pending',
    `expires_at`     DATETIME NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. تبلیغات (ads)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ads` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT UNSIGNED NOT NULL,
    `type`             VARCHAR(30) NOT NULL COMMENT 'banner|seo|custom_task|adsocial|adtube',
    `title`            VARCHAR(255) NULL,
    `description`      TEXT NULL,
    `url`              VARCHAR(512) NULL,
    `keyword`          VARCHAR(191) NULL,
    `image`            VARCHAR(255) NULL,
    `placement`        VARCHAR(50) NULL,
    `sort_order`       INT DEFAULT 0,
    `platform`         VARCHAR(50) NULL,
    `task_type`        VARCHAR(50) NULL,
    `status`           VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|active|exhausted|rejected|expired',
    `total_budget`     DECIMAL(20,2) DEFAULT 0.00,
    `remaining_budget` DECIMAL(20,2) DEFAULT 0.00,
    `price_per_click`  DECIMAL(10,4) DEFAULT 0.0000,
    `total_count`      INT DEFAULT 0 COMMENT 'برای تسک‌های سفارشی',
    `completed_count`  INT DEFAULT 0,
    `pending_count`    INT DEFAULT 0,
    `clicks`           INT DEFAULT 0,
    `clicks_count`     INT DEFAULT 0,
    `impressions`      INT DEFAULT 0,
    `ctr`              DECIMAL(5,2) DEFAULT 0.00,
    `is_active`        TINYINT(1) DEFAULT 1,
    `start_date`       DATETIME NULL,
    `end_date`         DATETIME NULL,
    `deadline`         DATETIME NULL,
    `deleted_at`       DATETIME NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_placement` (`placement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. کلیک‌های بنر (banner_clicks)
-- ============================================================
CREATE TABLE IF NOT EXISTS `banner_clicks` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `banner_id`    INT UNSIGNED NOT NULL,
    `user_id`      INT UNSIGNED NULL,
    `ip_address`   VARCHAR(45) NOT NULL,
    `clicked_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_banner_id` (`banner_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. جایگاه‌های بنر (banner_placements)
-- ============================================================
CREATE TABLE IF NOT EXISTS `banner_placements` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(500) NULL,
    `max_banners` INT DEFAULT 1,
    `is_active`   TINYINT(1) DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. سرمایه‌گذاری (investments)
-- ============================================================
CREATE TABLE IF NOT EXISTS `investments` (
    `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`              INT UNSIGNED NOT NULL,
    `amount`               DECIMAL(20,2) NOT NULL,
    `current_balance`      DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    `total_profit`         DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    `total_loss`           DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    `status`               VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|frozen|closed|suspended',
    `start_date`           DATETIME NULL,
    `last_withdrawal_date` DATETIME NULL,
    `deposit_lock_until`   DATETIME NULL,
    `deleted_at`           TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 یا timestamp (قدیمی)',
    `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. سود/ضرر هفتگی سرمایه‌گذاری (investment_profits)
-- ============================================================
CREATE TABLE IF NOT EXISTS `investment_profits` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `investment_id` INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `amount`        DECIMAL(20,2) NOT NULL,
    `type`          VARCHAR(10) NOT NULL COMMENT 'profit|loss',
    `rate`          DECIMAL(5,2) NULL,
    `week_start`    DATE NULL,
    `week_end`      DATE NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_investment_id` (`investment_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. برداشت از سرمایه‌گذاری (investment_withdrawals)
-- ============================================================
CREATE TABLE IF NOT EXISTS `investment_withdrawals` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `investment_id` INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `amount`        DECIMAL(20,2) NOT NULL,
    `status`        VARCHAR(20) NOT NULL DEFAULT 'pending',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_investment_id` (`investment_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. کمیسیون‌های معرفی (referral_commissions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `referral_commissions` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `referrer_id`       INT UNSIGNED NOT NULL,
    `referred_id`       INT UNSIGNED NOT NULL,
    `source_type`       VARCHAR(50) NOT NULL COMMENT 'deposit|task|purchase|...',
    `source_id`         INT UNSIGNED NULL,
    `source_amount`     DECIMAL(20,2) NOT NULL,
    `commission_percent`DECIMAL(5,2) NOT NULL,
    `commission_amount` DECIMAL(20,2) NOT NULL,
    `currency`          VARCHAR(10) NOT NULL DEFAULT 'irt',
    `status`            VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|cancelled',
    `idempotency_key`   VARCHAR(128) NOT NULL UNIQUE,
    `transaction_id`    VARCHAR(64) NULL,
    `metadata`          JSON NULL,
    `ip_address`        VARCHAR(45) NULL,
    `paid_at`           DATETIME NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_referrer_id` (`referrer_id`),
    INDEX `idx_referred_id` (`referred_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. لاگ فعالیت‌های معرفی (referral_activity_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `referral_activity_logs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `action`      VARCHAR(50) NOT NULL COMMENT 'signup|deposit|...',
    `ip_address`  VARCHAR(45) NULL,
    `metadata`    JSON NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action` (`action`),
    INDEX `idx_ip` (`ip_address`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. KYC احراز هویت (kyc_verifications)
-- ============================================================
CREATE TABLE IF NOT EXISTS `kyc_verifications` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT UNSIGNED NOT NULL,
    `verification_image`  VARCHAR(255) NULL,
    `national_code`       VARCHAR(20) NULL,
    `birth_date`          DATE NULL,
    `document_front`      VARCHAR(255) NULL,
    `document_back`       VARCHAR(255) NULL,
    `selfie`              VARCHAR(255) NULL,
    `status`              VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|under_review|verified|rejected',
    `rejection_reason`    TEXT NULL,
    `reviewed_at`         DATETIME NULL,
    `verified_at`         DATETIME NULL,
    `expires_at`          DATETIME NULL,
    `ip_address`          VARCHAR(45) NULL,
    `user_agent`          VARCHAR(512) NULL,
    `device_fingerprint`  VARCHAR(128) NULL,
    `documents_deleted`   TINYINT(1) DEFAULT 0,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. اعلان‌ها (notifications)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `type`        VARCHAR(50) NOT NULL COMMENT 'system|payment|task|referral|security|...',
    `title`       VARCHAR(255) NOT NULL,
    `message`     TEXT NOT NULL,
    `data`        JSON NULL,
    `action_url`  VARCHAR(512) NULL,
    `action_text` VARCHAR(100) NULL,
    `priority`    VARCHAR(10) NOT NULL DEFAULT 'normal' COMMENT 'low|normal|high|urgent',
    `is_read`     TINYINT(1) DEFAULT 0,
    `read_at`     DATETIME NULL,
    `is_archived` TINYINT(1) DEFAULT 0,
    `archived_at` DATETIME NULL,
    `is_deleted`  TINYINT(1) DEFAULT 0,
    `deleted_at`  DATETIME NULL,
    `clicked_at`  DATETIME NULL,
    `expires_at`  DATETIME NULL,
    `scheduled_at`DATETIME NULL,
    `sent_at`     DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_is_archived` (`is_archived`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 21. ترجیحات اعلان (notification_preferences)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`       INT UNSIGNED NOT NULL UNIQUE,
    `email_enabled` TINYINT(1) DEFAULT 1,
    `sms_enabled`   TINYINT(1) DEFAULT 0,
    `push_enabled`  TINYINT(1) DEFAULT 1,
    `preferences`   JSON NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 22. امتیازات کاربران (user_scores) - جدول اصلی
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_scores` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NOT NULL UNIQUE,
    `loyalty_score`  DECIMAL(10,2) DEFAULT 0.00,
    `fraud_score`    INT DEFAULT 0,
    `social_trust`   DECIMAL(10,2) DEFAULT 0.00,
    `influencer_score` DECIMAL(10,2) DEFAULT 0.00,
    `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 23. رویدادهای امتیاز کاربر (user_score_events)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_score_events` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `domain`     VARCHAR(50) NOT NULL COMMENT 'fraud|loyalty|influencer|social_trust',
    `source`     VARCHAR(100) NOT NULL,
    `delta`      DECIMAL(10,2) NOT NULL,
    `meta_json`  JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_domain` (`domain`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 24. رویدادهای امتیاز یکپارچه (score_events)
-- ============================================================
CREATE TABLE IF NOT EXISTS `score_events` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entity_type` VARCHAR(30) NOT NULL COMMENT 'user|profile|...',
    `entity_id`   INT UNSIGNED NOT NULL,
    `domain`      VARCHAR(50) NOT NULL,
    `delta`       DECIMAL(10,2) NOT NULL,
    `source`      VARCHAR(100) NOT NULL,
    `meta_json`   JSON NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 25. تنظیمات امتیاز (user_score_adjustments)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_score_adjustments` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `domain`     VARCHAR(50) NOT NULL,
    `operation`  VARCHAR(20) NOT NULL COMMENT 'add|subtract|multiply|set',
    `value`      DECIMAL(10,2) NOT NULL,
    `reason`     VARCHAR(500) NULL,
    `expires_at` DATETIME NULL,
    `created_by` INT UNSIGNED NULL,
    `is_active`  TINYINT(1) DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 26. Trust Score کاربران (user_trust_scores)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_trust_scores` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL UNIQUE,
    `trust_score` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 27. Snapshot امتیاز اعتماد (user_trust_snapshots)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_trust_snapshots` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `trust_score`     DECIMAL(5,2) NOT NULL,
    `week_good_tasks` INT DEFAULT 0,
    `week_rejected`   INT DEFAULT 0,
    `week_soft`       INT DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 28. اجرای تسک‌ها (task_executions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `task_executions` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id`      INT UNSIGNED NOT NULL,
    `executor_id`  INT UNSIGNED NOT NULL,
    `status`       VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|submitted|completed|soft_approved|rejected',
    `final_score`  DECIMAL(5,2) DEFAULT 0.00,
    `fraud_score`  DECIMAL(5,2) DEFAULT 0.00,
    `proof`        VARCHAR(512) NULL,
    `rejection_reason` TEXT NULL,
    `reviewed_by`  INT UNSIGNED NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_task_id` (`task_id`),
    INDEX `idx_executor_id` (`executor_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 29. سشن‌های کاربران (user_sessions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`            INT UNSIGNED NOT NULL,
    `session_token`      VARCHAR(128) NULL,
    `ip_address`         VARCHAR(45) NULL,
    `user_agent`         VARCHAR(512) NULL,
    `device_fingerprint` VARCHAR(128) NULL,
    `country`            VARCHAR(50) NULL,
    `city`               VARCHAR(100) NULL,
    `latitude`           DECIMAL(10,6) NULL,
    `longitude`          DECIMAL(10,6) NULL,
    `is_active`          TINYINT(1) DEFAULT 1,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_ip_address` (`ip_address`),
    INDEX `idx_device_fingerprint` (`device_fingerprint`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 30. اثر انگشت دستگاه (user_fingerprints)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_fingerprints` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `fingerprint` VARCHAR(128) NOT NULL,
    `metadata`    JSON NULL,
    `last_seen`   DATETIME NULL,
    `seen_count`  INT DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_fingerprint` (`user_id`, `fingerprint`),
    INDEX `idx_fingerprint` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 31. لیست سیاه IP (ip_blacklist)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ip_blacklist` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address`   VARCHAR(45) NOT NULL UNIQUE,
    `reason`       VARCHAR(500) NULL,
    `auto_blocked` TINYINT(1) DEFAULT 0,
    `expires_at`   DATETIME NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 32. لیست سیاه دستگاه (device_blacklist)
-- ============================================================
CREATE TABLE IF NOT EXISTS `device_blacklist` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fingerprint`  VARCHAR(128) NOT NULL UNIQUE,
    `reason`       VARCHAR(500) NULL,
    `auto_blocked` TINYINT(1) DEFAULT 0,
    `expires_at`   DATETIME NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 33. لیست سیاه کاربران (user_blacklist)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_blacklist` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL UNIQUE,
    `reason`     VARCHAR(500) NULL,
    `blocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 34. گره‌های TOR (tor_exit_nodes)
-- ============================================================
CREATE TABLE IF NOT EXISTS `tor_exit_nodes` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL UNIQUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 35. محدوده‌های VPN (vpn_ranges)
-- ============================================================
CREATE TABLE IF NOT EXISTS `vpn_ranges` (
    `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_range`  VARCHAR(50) NOT NULL UNIQUE,
    `created_at`DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 36. لاگ‌های تقلب (fraud_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fraud_logs` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NULL,
    `fraud_type`   VARCHAR(50) NOT NULL,
    `risk_score`   INT DEFAULT 0,
    `details`      JSON NULL,
    `action_taken` VARCHAR(100) NULL,
    `ip_address`   VARCHAR(45) NULL,
    `user_agent`   VARCHAR(512) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_fraud_type` (`fraud_type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 37. پرچم‌های تقلب کاربر (user_fraud_flags)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_fraud_flags` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT UNSIGNED NOT NULL,
    `flag_type`        VARCHAR(50) NOT NULL,
    `reputation_score` INT DEFAULT 0,
    `details`          JSON NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 38. لاگ محاسبه تقلب (fraud_calculation_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fraud_calculation_logs` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT UNSIGNED NOT NULL,
    `account_age_factor`  DECIMAL(5,2) NULL,
    `reputation_factor`   DECIMAL(5,2) NULL,
    `velocity_factor`     DECIMAL(5,2) NULL,
    `geo_factor`          DECIMAL(5,2) NULL,
    `device_factor`       DECIMAL(5,2) NULL,
    `final_score`         INT NOT NULL,
    `factors_json`        JSON NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 39. پیش‌بینی‌های ML تقلب (ml_fraud_predictions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ml_fraud_predictions` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NOT NULL,
    `risk_score`     DECIMAL(5,4) NOT NULL,
    `features`       JSON NULL,
    `actual_outcome` VARCHAR(30) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 40. هوش ایمیل (email_intelligence)
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_intelligence` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`            VARCHAR(191) NOT NULL UNIQUE,
    `domain`           VARCHAR(100) NULL,
    `is_disposable`    TINYINT(1) DEFAULT 0,
    `is_free_provider` TINYINT(1) DEFAULT 0,
    `mx_records_valid` TINYINT(1) DEFAULT 1,
    `last_checked_at`  DATETIME NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 41. هوش شماره تلفن (phone_intelligence)
-- ============================================================
CREATE TABLE IF NOT EXISTS `phone_intelligence` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `phone`           VARCHAR(30) NOT NULL UNIQUE,
    `country_code`    VARCHAR(5) NULL,
    `line_type`       VARCHAR(20) NULL COMMENT 'mobile|landline|voip',
    `is_voip`         TINYINT(1) DEFAULT 0,
    `is_valid`        TINYINT(1) DEFAULT 1,
    `last_checked_at` DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 42. هوش دستگاه (device_intelligence)
-- ============================================================
CREATE TABLE IF NOT EXISTS `device_intelligence` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NULL,
    `fingerprint`     VARCHAR(128) NOT NULL,
    `device_info`     JSON NULL,
    `analysis_result` JSON NULL,
    `risk_score`      DECIMAL(5,2) DEFAULT 0.00,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fingerprint` (`fingerprint`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 43. الگوهای تایپ کاربر (user_typing_patterns)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_typing_patterns` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `avg_interval`    DECIMAL(10,4) NULL,
    `stddev_interval` DECIMAL(10,4) NULL,
    `avg_hold_time`   DECIMAL(10,4) NULL,
    `keystroke_count` INT DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 44. پروفایل اینفلوئنسر (influencer_profiles)
-- ============================================================
CREATE TABLE IF NOT EXISTS `influencer_profiles` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`            INT UNSIGNED NOT NULL,
    `platform`           VARCHAR(30) NOT NULL DEFAULT 'instagram' COMMENT 'instagram|telegram|youtube|...',
    `username`           VARCHAR(100) NOT NULL,
    `page_url`           VARCHAR(512) NULL,
    `profile_image`      VARCHAR(255) NULL,
    `follower_count`     INT DEFAULT 0,
    `engagement_rate`    DECIMAL(5,2) DEFAULT 0.00,
    `category`           VARCHAR(50) NULL,
    `bio`                TEXT NULL,
    `story_price_24h`    DECIMAL(20,2) DEFAULT 0.00,
    `post_price_24h`     DECIMAL(20,2) DEFAULT 0.00,
    `post_price_48h`     DECIMAL(20,2) DEFAULT 0.00,
    `post_price_72h`     DECIMAL(20,2) DEFAULT 0.00,
    `currency`           VARCHAR(10) DEFAULT 'irt',
    `status`             VARCHAR(30) NOT NULL DEFAULT 'pending',
    `verification_code`  VARCHAR(64) NULL,
    `verification_post_url` VARCHAR(512) NULL,
    `total_orders`       INT DEFAULT 0,
    `completed_orders`   INT DEFAULT 0,
    `average_rating`     DECIMAL(3,2) DEFAULT 0.00,
    `is_active`          TINYINT(1) DEFAULT 1,
    `priority`           INT DEFAULT 0,
    `rejection_reason`   TEXT NULL,
    `verified_by`        INT UNSIGNED NULL,
    `verified_at`        DATETIME NULL,
    `suspended_at`       DATETIME NULL,
    `suspended_reason`   TEXT NULL,
    `deleted_at`         DATETIME NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_platform` (`platform`),
    INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 45. تأییدیه‌های اینفلوئنسر (influencer_verifications)
-- ============================================================
CREATE TABLE IF NOT EXISTS `influencer_verifications` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `profile_id`       INT UNSIGNED NOT NULL,
    `code`             VARCHAR(64) NOT NULL,
    `status`           VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|submitted|approved|rejected|expired',
    `proof_url`        VARCHAR(512) NULL,
    `expires_at`       DATETIME NULL,
    `submitted_at`     DATETIME NULL,
    `approved_at`      DATETIME NULL,
    `rejection_reason` TEXT NULL,
    `approved_by`      INT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_profile_id` (`profile_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 46. شهرت اینفلوئنسر (influencer_reputation_events)
-- ============================================================
CREATE TABLE IF NOT EXISTS `influencer_reputation_events` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `profile_id` INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NULL,
    `order_id`   INT UNSIGNED NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `points`     INT DEFAULT 0,
    `note`       VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_profile_id` (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 47. سفارش‌های استوری (story_orders)
-- ============================================================
CREATE TABLE IF NOT EXISTS `story_orders` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`       INT UNSIGNED NOT NULL COMMENT 'خریدار',
    `influencer_id` INT UNSIGNED NOT NULL COMMENT 'influencer_profiles.id',
    `type`          VARCHAR(30) NOT NULL COMMENT 'story|post_24h|post_48h|post_72h',
    `amount`        DECIMAL(20,2) NOT NULL,
    `currency`      VARCHAR(10) DEFAULT 'irt',
    `status`        VARCHAR(20) NOT NULL DEFAULT 'pending',
    `brief`         TEXT NULL,
    `delivered_at`  DATETIME NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_influencer_id` (`influencer_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 48. اسکرو (escrow)
-- ============================================================
CREATE TABLE IF NOT EXISTS `escrow` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id`     INT UNSIGNED NOT NULL,
    `payer_id`     INT UNSIGNED NOT NULL,
    `payee_id`     INT UNSIGNED NOT NULL,
    `amount`       DECIMAL(20,2) NOT NULL,
    `currency`     VARCHAR(10) DEFAULT 'irt',
    `status`       VARCHAR(20) NOT NULL DEFAULT 'held' COMMENT 'held|released|refunded|disputed',
    `released_at`  DATETIME NULL,
    `dispute_id`   INT UNSIGNED NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_payer_id` (`payer_id`),
    INDEX `idx_payee_id` (`payee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 49. اختلافات (disputes)
-- ============================================================
CREATE TABLE IF NOT EXISTS `disputes` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ref_type`    VARCHAR(30) NOT NULL COMMENT 'influencer|task|...',
    `ref_id`      INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `status`      VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|under_review|resolved|closed',
    `reason`      TEXT NULL,
    `resolution`  TEXT NULL,
    `resolved_by` INT UNSIGNED NULL,
    `resolved_at` DATETIME NULL,
    `read_at`     DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ref` (`ref_type`, `ref_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 50. محتوای ارسال‌شده (content_submissions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `content_submissions` (
    `id`                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`                 INT UNSIGNED NOT NULL,
    `platform`                VARCHAR(30) NOT NULL COMMENT 'youtube|aparat|...',
    `video_url`               VARCHAR(512) NOT NULL,
    `title`                   VARCHAR(255) NOT NULL,
    `description`             TEXT NULL,
    `category`                VARCHAR(50) NULL,
    `status`                  VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|published|rejected',
    `agreement_accepted`      TINYINT(1) DEFAULT 0,
    `agreement_accepted_at`   DATETIME NULL,
    `agreement_ip`            VARCHAR(45) NULL,
    `agreement_fingerprint`   VARCHAR(128) NULL,
    `rejection_reason`        TEXT NULL,
    `is_deleted`              TINYINT(1) DEFAULT 0,
    `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 51. درآمد محتوا (content_revenues)
-- ============================================================
CREATE TABLE IF NOT EXISTS `content_revenues` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `submission_id`   INT UNSIGNED NOT NULL,
    `user_id`         INT UNSIGNED NOT NULL,
    `gross_amount`    DECIMAL(20,2) NOT NULL,
    `platform_fee`    DECIMAL(20,2) DEFAULT 0.00,
    `net_user_amount` DECIMAL(20,2) NOT NULL,
    `currency`        VARCHAR(10) DEFAULT 'irt',
    `status`          VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|paid',
    `paid_at`         DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_submission_id` (`submission_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 52. موافقت‌نامه محتوا (content_agreements)
-- ============================================================
CREATE TABLE IF NOT EXISTS `content_agreements` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `version`     VARCHAR(20) NOT NULL,
    `accepted_at` DATETIME NOT NULL,
    `ip_address`  VARCHAR(45) NULL,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 53. دورهای لاتاری (lottery_rounds)
-- ============================================================
CREATE TABLE IF NOT EXISTS `lottery_rounds` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`        VARCHAR(255) NOT NULL,
    `status`       VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|closed|finished',
    `prize_amount` DECIMAL(20,2) DEFAULT 0.00,
    `currency`     VARCHAR(10) DEFAULT 'irt',
    `starts_at`    DATETIME NULL,
    `ends_at`      DATETIME NULL,
    `drawn_at`     DATETIME NULL,
    `winner_id`    INT UNSIGNED NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 54. مشارکت در لاتاری (lottery_participations)
-- ============================================================
CREATE TABLE IF NOT EXISTS `lottery_participations` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `round_id`     INT UNSIGNED NOT NULL,
    `chance_score` DECIMAL(10,2) DEFAULT 0.00,
    `status`       VARCHAR(20) NOT NULL DEFAULT 'active',
    `is_deleted`   TINYINT(1) DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_round_id` (`round_id`),
    UNIQUE KEY `uk_user_round` (`user_id`, `round_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 55. رأی‌گیری لاتاری (lottery_votes)
-- ============================================================
CREATE TABLE IF NOT EXISTS `lottery_votes` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `round_id`   INT UNSIGNED NOT NULL,
    `vote`       VARCHAR(10) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_round_id` (`round_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 56. لاگ شانس لاتاری (lottery_chance_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `lottery_chance_logs` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `round_id`     INT UNSIGNED NOT NULL,
    `delta`        DECIMAL(10,2) NOT NULL,
    `source`       VARCHAR(100) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_round_id` (`round_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 57. اعداد روزانه لاتاری (lottery_daily_numbers)
-- ============================================================
CREATE TABLE IF NOT EXISTS `lottery_daily_numbers` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `round_id`    INT UNSIGNED NOT NULL,
    `date`        DATE NOT NULL,
    `number`      VARCHAR(20) NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_round_id` (`round_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 58. بازی پیش‌بینی (prediction_games)
-- ============================================================
CREATE TABLE IF NOT EXISTS `prediction_games` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`        VARCHAR(255) NOT NULL,
    `description`  TEXT NULL,
    `type`         VARCHAR(30) NOT NULL,
    `options`      JSON NULL,
    `status`       VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|closed|resolved',
    `result`       VARCHAR(100) NULL,
    `prize_pool`   DECIMAL(20,2) DEFAULT 0.00,
    `currency`     VARCHAR(10) DEFAULT 'irt',
    `starts_at`    DATETIME NULL,
    `ends_at`      DATETIME NULL,
    `resolved_at`  DATETIME NULL,
    `created_by`   INT UNSIGNED NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 59. شرط‌بندی پیش‌بینی (prediction_bets)
-- ============================================================
CREATE TABLE IF NOT EXISTS `prediction_bets` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `game_id`     INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `option`      VARCHAR(100) NOT NULL,
    `amount`      DECIMAL(20,2) NOT NULL,
    `currency`    VARCHAR(10) DEFAULT 'irt',
    `status`      VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|won|lost',
    `payout`      DECIMAL(20,2) DEFAULT 0.00,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_game_id` (`game_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 60. کوپن‌ها (coupons)
-- ============================================================
CREATE TABLE IF NOT EXISTS `coupons` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`            VARCHAR(50) NOT NULL UNIQUE,
    `type`            VARCHAR(20) NOT NULL COMMENT 'percent|fixed',
    `value`           DECIMAL(10,2) NOT NULL,
    `min_amount`      DECIMAL(20,2) DEFAULT 0.00,
    `max_discount`    DECIMAL(20,2) NULL,
    `usage_limit`     INT NULL,
    `usage_count`     INT DEFAULT 0,
    `per_user_limit`  INT DEFAULT 1,
    `status`          VARCHAR(20) DEFAULT 'active' COMMENT 'active|inactive|expired',
    `valid_from`      DATETIME NULL,
    `valid_until`     DATETIME NULL,
    `created_by`      INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_code` (`code`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 61. استفاده از کوپن (coupon_redemptions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `coupon_id`       INT UNSIGNED NOT NULL,
    `user_id`         INT UNSIGNED NOT NULL,
    `order_id`        INT UNSIGNED NULL,
    `discount_amount` DECIMAL(20,2) NOT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_coupon_id` (`coupon_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 62. Feature Flags (feature_flags)
-- ============================================================
CREATE TABLE IF NOT EXISTS `feature_flags` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`                VARCHAR(100) NOT NULL UNIQUE,
    `description`         TEXT NULL,
    `is_enabled`          TINYINT(1) NOT NULL DEFAULT 1,
    `rollout_percentage`  INT NOT NULL DEFAULT 100,
    `enabled_percentage`  INT NOT NULL DEFAULT 100,
    `allowed_users`       JSON NULL,
    `enabled_for_users`   JSON NULL,
    `enabled_for_roles`   JSON NULL,
    `targeted_user_ids`   JSON NULL,
    `targeted_roles`      JSON NULL,
    `targeted_countries`  JSON NULL,
    `targeted_plans`      JSON NULL,
    `targeted_devices`    JSON NULL,
    `targeted_routes`     JSON NULL,
    `target_age_min`      INT NULL,
    `target_age_max`      INT NULL,
    `percentage_rollout`  INT DEFAULT 100,
    `depends_on`          JSON NULL,
    `environments`        JSON NULL,
    `enabled_from`        DATETIME NULL,
    `enabled_until`       DATETIME NULL,
    `created_by`          INT UNSIGNED NULL,
    `updated_by`          INT UNSIGNED NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 63. کش Feature Flag (feature_flag_cache)
-- ============================================================
CREATE TABLE IF NOT EXISTS `feature_flag_cache` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feature_name` VARCHAR(100) NOT NULL,
    `user_id`      INT UNSIGNED NULL,
    `result`       TINYINT(1) NOT NULL,
    `expires_at`   DATETIME NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_feature_user` (`feature_name`, `user_id`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 64. لاگ Activity (activity_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NULL,
    `action`     VARCHAR(100) NOT NULL COMMENT 'login|logout|password_changed|email_changed|login_failed|...',
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(512) NULL,
    `details`    JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 65. Audit Trail (audit_trail)
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_trail` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NULL,
    `admin_id`     INT UNSIGNED NULL,
    `action`       VARCHAR(100) NOT NULL,
    `entity_type`  VARCHAR(50) NULL,
    `entity_id`    INT UNSIGNED NULL,
    `old_data`     JSON NULL,
    `new_data`     JSON NULL,
    `ip_address`   VARCHAR(45) NULL,
    `user_agent`   VARCHAR(512) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 66. Audit Events (audit_events)
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_events` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_id`    INT UNSIGNED NULL,
    `actor_type`  VARCHAR(30) DEFAULT 'user',
    `event`       VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NULL,
    `entity_id`   INT UNSIGNED NULL,
    `payload`     JSON NULL,
    `ip_address`  VARCHAR(45) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_actor_id` (`actor_id`),
    INDEX `idx_event` (`event`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 67. لاگ امنیتی (security_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `security_logs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `event_type`  VARCHAR(50) NOT NULL,
    `severity`    VARCHAR(10) DEFAULT 'info' COMMENT 'info|warning|critical',
    `ip_address`  VARCHAR(45) NULL,
    `user_agent`  VARCHAR(512) NULL,
    `details`     JSON NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_event_type` (`event_type`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 68. رویدادهای امنیتی (security_events)
-- ============================================================
CREATE TABLE IF NOT EXISTS `security_events` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `metadata`   JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 69. صف ایمیل (email_queue)
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_queue` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `to_email`     VARCHAR(191) NOT NULL,
    `to_name`      VARCHAR(100) NULL,
    `subject`      VARCHAR(255) NOT NULL,
    `body`         TEXT NOT NULL,
    `template`     VARCHAR(100) NULL,
    `variables`    JSON NULL,
    `status`       VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|failed',
    `attempts`     INT DEFAULT 0,
    `sent_at`      DATETIME NULL,
    `failed_at`    DATETIME NULL,
    `error`        TEXT NULL,
    `scheduled_at` DATETIME NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled_at` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 70. نقش‌ها (roles)
-- ============================================================
CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(50) NOT NULL UNIQUE,
    `label`       VARCHAR(100) NULL,
    `description` TEXT NULL,
    `is_system`   TINYINT(1) DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 71. مجوزها (permissions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `permissions` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL UNIQUE,
    `label`       VARCHAR(100) NULL,
    `group`       VARCHAR(50) NULL,
    `description` TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 72. مجوزهای نقش (role_permissions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`       INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 73. صفحات (pages)
-- ============================================================
CREATE TABLE IF NOT EXISTS `pages` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug`         VARCHAR(100) NOT NULL UNIQUE,
    `title`        VARCHAR(255) NOT NULL,
    `content`      LONGTEXT NULL,
    `meta_title`   VARCHAR(255) NULL,
    `meta_desc`    VARCHAR(500) NULL,
    `is_published` TINYINT(1) DEFAULT 1,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 74. توکن API (api_tokens)
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `name`         VARCHAR(100) NOT NULL,
    `token`        VARCHAR(128) NOT NULL UNIQUE,
    `token_hash`   VARCHAR(128) NULL,
    `abilities`    JSON NULL,
    `last_used_at` DATETIME NULL,
    `expires_at`   DATETIME NULL,
    `is_active`    TINYINT(1) DEFAULT 1,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 75. تنظیمات سیستم (system_settings)
-- ============================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`         VARCHAR(100) NOT NULL UNIQUE,
    `value`       TEXT NULL,
    `type`        VARCHAR(20) DEFAULT 'string' COMMENT 'string|int|bool|json',
    `group`       VARCHAR(50) NULL,
    `description` VARCHAR(500) NULL,
    `updated_by`  INT UNSIGNED NULL,
    `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 76. لاگ عملکرد (performance_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `performance_logs` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `route`        VARCHAR(255) NULL,
    `method`       VARCHAR(10) NULL,
    `response_time`DECIMAL(10,4) NULL COMMENT 'ثانیه',
    `memory_usage` INT NULL COMMENT 'بایت',
    `query_count`  INT NULL,
    `status_code`  INT NULL,
    `ip_address`   VARCHAR(45) NULL,
    `user_id`      INT UNSIGNED NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_route` (`route`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 77. لاگ حذف حساب (account_deletion_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `account_deletion_logs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `reason`      TEXT NULL,
    `deleted_by`  INT UNSIGNED NULL COMMENT 'NULL = self-delete',
    `ip_address`  VARCHAR(45) NULL,
    `metadata`    JSON NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 78. لاگ بکاپ (backup_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `backup_logs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `filename`    VARCHAR(255) NOT NULL,
    `size`        BIGINT NULL,
    `status`      VARCHAR(20) NOT NULL DEFAULT 'success',
    `note`        TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 79. لاگ لاگ Captcha (captcha_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `captcha_logs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `ip_address`  VARCHAR(45) NULL,
    `result`      VARCHAR(10) NOT NULL COMMENT 'pass|fail',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 80. پیام‌های مستقیم (direct_messages)
-- ============================================================
CREATE TABLE IF NOT EXISTS `direct_messages` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sender_id`   INT UNSIGNED NOT NULL,
    `receiver_id` INT UNSIGNED NOT NULL,
    `message`     TEXT NOT NULL,
    `is_read`     TINYINT(1) DEFAULT 0,
    `read_at`     DATETIME NULL,
    `deleted_at`  DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sender_id` (`sender_id`),
    INDEX `idx_receiver_id` (`receiver_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 81. تیکت‌ها (tickets)
-- ============================================================
CREATE TABLE IF NOT EXISTS `tickets` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `subject`      VARCHAR(255) NOT NULL,
    `status`       VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|in_progress|closed|resolved',
    `priority`     VARCHAR(10) NOT NULL DEFAULT 'normal' COMMENT 'low|normal|high|urgent',
    `department`   VARCHAR(50) NULL,
    `assigned_to`  INT UNSIGNED NULL,
    `closed_at`    DATETIME NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 82. پیام‌های تیکت (ticket_messages)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ticket_messages` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id`   INT UNSIGNED NOT NULL,
    `sender_id`   INT UNSIGNED NOT NULL,
    `message`     TEXT NOT NULL,
    `is_admin`    TINYINT(1) DEFAULT 0,
    `attachments` JSON NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 83. گزارش باگ (bug_reports)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bug_reports` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `title`       VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `steps`       TEXT NULL,
    `severity`    VARCHAR(20) DEFAULT 'medium' COMMENT 'low|medium|high|critical',
    `status`      VARCHAR(20) DEFAULT 'open' COMMENT 'open|in_progress|resolved|closed',
    `screenshot`  VARCHAR(255) NULL,
    `ip_address`  VARCHAR(45) NULL,
    `user_agent`  VARCHAR(512) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 84. پیام‌های تماس (contact_messages)
-- ============================================================
CREATE TABLE IF NOT EXISTS `contact_messages` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL,
    `email`       VARCHAR(191) NOT NULL,
    `subject`     VARCHAR(255) NULL,
    `message`     TEXT NOT NULL,
    `is_read`     TINYINT(1) DEFAULT 0,
    `ip_address`  VARCHAR(45) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 85. دسترسی فایل (file_access)
-- ============================================================
CREATE TABLE IF NOT EXISTS `file_access` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `file_path`   VARCHAR(512) NOT NULL,
    `file_type`   VARCHAR(50) NULL,
    `access_type` VARCHAR(20) DEFAULT 'read' COMMENT 'read|write|delete',
    `ip_address`  VARCHAR(45) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 86. KPI آمار (kpi_statistics)
-- ============================================================
CREATE TABLE IF NOT EXISTS `kpi_statistics` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `metric`      VARCHAR(100) NOT NULL,
    `value`       DECIMAL(20,4) NOT NULL,
    `period`      VARCHAR(20) NULL COMMENT 'daily|weekly|monthly',
    `date`        DATE NULL,
    `metadata`    JSON NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_metric` (`metric`),
    INDEX `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 87. داده‌های تحلیلی (advanced_analytics)
-- ============================================================
CREATE TABLE IF NOT EXISTS `advanced_analytics` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event`       VARCHAR(100) NOT NULL,
    `user_id`     INT UNSIGNED NULL,
    `properties`  JSON NULL,
    `session_id`  VARCHAR(64) NULL,
    `ip_address`  VARCHAR(45) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_event` (`event`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 88. گیت‌وی پرداخت (payment_gateways)
-- ============================================================
CREATE TABLE IF NOT EXISTS `payment_gateways` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(50) NOT NULL UNIQUE,
    `label`      VARCHAR(100) NULL,
    `is_active`  TINYINT(1) DEFAULT 1,
    `config`     JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 89. لاگ پرداخت (payment_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `payment_logs` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NULL,
    `gateway`        VARCHAR(50) NULL,
    `amount`         DECIMAL(20,2) NULL,
    `currency`       VARCHAR(10) NULL,
    `status`         VARCHAR(20) NULL,
    `request_data`   JSON NULL,
    `response_data`  JSON NULL,
    `error`          TEXT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 90. دفتر کل (ledger_entries)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ledger_entries` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NOT NULL,
    `transaction_id` VARCHAR(64) NULL,
    `type`           VARCHAR(20) NOT NULL COMMENT 'debit|credit',
    `amount`         DECIMAL(20,2) NOT NULL,
    `currency`       VARCHAR(10) DEFAULT 'irt',
    `balance_after`  DECIMAL(20,2) NOT NULL,
    `description`    VARCHAR(500) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 91. پرداخت‌های زمان‌بندی‌شده (scheduled_payments)
-- ============================================================
CREATE TABLE IF NOT EXISTS `scheduled_payments` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `amount`       DECIMAL(20,2) NOT NULL,
    `currency`     VARCHAR(10) DEFAULT 'irt',
    `type`         VARCHAR(50) NOT NULL,
    `status`       VARCHAR(20) DEFAULT 'pending',
    `scheduled_at` DATETIME NOT NULL,
    `executed_at`  DATETIME NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_scheduled_at` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 92. ریتینگ (ratings)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ratings` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `rater_id`    INT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(30) NOT NULL,
    `entity_id`   INT UNSIGNED NOT NULL,
    `score`       TINYINT NOT NULL COMMENT '1-5',
    `comment`     TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rater_id` (`rater_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 93. تعاملات (interaction_model)
-- ============================================================
CREATE TABLE IF NOT EXISTS `interactions` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `entity_type`  VARCHAR(30) NOT NULL,
    `entity_id`    INT UNSIGNED NOT NULL,
    `type`         VARCHAR(30) NOT NULL COMMENT 'view|click|like|share|...',
    `metadata`     JSON NULL,
    `ip_address`   VARCHAR(45) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 94. سطح کاربران (user_levels) - برای LevelController
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_levels` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`            VARCHAR(50) NOT NULL UNIQUE,
    `label`           VARCHAR(100) NULL,
    `min_score`       INT DEFAULT 0,
    `benefits`        JSON NULL,
    `is_active`       TINYINT(1) DEFAULT 1,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 95. ارتقاع سطح (level_upgrades)
-- ============================================================
CREATE TABLE IF NOT EXISTS `level_upgrades` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `from_level`  VARCHAR(50) NULL,
    `to_level`    VARCHAR(50) NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 96. حساب‌های شبکه اجتماعی (social_accounts)
-- ============================================================
CREATE TABLE IF NOT EXISTS `social_accounts` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `platform`    VARCHAR(30) NOT NULL,
    `username`    VARCHAR(100) NULL,
    `account_id`  VARCHAR(100) NULL,
    `access_token`VARCHAR(512) NULL,
    `is_verified` TINYINT(1) DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 97. تسک‌های اجتماعی (social_tasks)
-- ============================================================
CREATE TABLE IF NOT EXISTS `social_tasks` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ad_id`       INT UNSIGNED NULL COMMENT 'ads.id',
    `platform`    VARCHAR(30) NOT NULL,
    `task_type`   VARCHAR(50) NOT NULL COMMENT 'follow|like|comment|share|view|...',
    `target_url`  VARCHAR(512) NOT NULL,
    `reward`      DECIMAL(10,2) DEFAULT 0.00,
    `currency`    VARCHAR(10) DEFAULT 'irt',
    `status`      VARCHAR(20) DEFAULT 'active',
    `max_doers`   INT NULL,
    `done_count`  INT DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_platform` (`platform`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 98. ارسال‌های تسک اجتماعی (social_task_submissions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `social_task_submissions` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id`     INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `proof_url`   VARCHAR(512) NULL,
    `status`      VARCHAR(20) DEFAULT 'pending' COMMENT 'pending|approved|rejected',
    `reward_paid` TINYINT(1) DEFAULT 0,
    `reviewed_by` INT UNSIGNED NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_task_id` (`task_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 99. خروجی داده (data_exports)
-- ============================================================
CREATE TABLE IF NOT EXISTS `data_exports` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `type`        VARCHAR(50) NOT NULL,
    `filename`    VARCHAR(255) NULL,
    `status`      VARCHAR(20) DEFAULT 'pending',
    `filters`     JSON NULL,
    `row_count`   INT NULL,
    `file_size`   BIGINT NULL,
    `error`       TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 100. عملیات دسته‌جمعی (bulk_operations)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bulk_operations` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type`        VARCHAR(50) NOT NULL,
    `admin_id`    INT UNSIGNED NULL,
    `status`      VARCHAR(20) DEFAULT 'pending',
    `total`       INT DEFAULT 0,
    `processed`   INT DEFAULT 0,
    `failed`      INT DEFAULT 0,
    `results`     JSON NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 101. سیاست ریسک (risk_policies)
-- ============================================================
CREATE TABLE IF NOT EXISTS `risk_policies` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL,
    `rules`       JSON NOT NULL,
    `is_active`   TINYINT(1) DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 102. ویترین (vitrine) - برای VitrineController
-- ============================================================
CREATE TABLE IF NOT EXISTS `vitrine_items` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `title`       VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `image`       VARCHAR(255) NULL,
    `price`       DECIMAL(20,2) NULL,
    `currency`    VARCHAR(10) DEFAULT 'irt',
    `category`    VARCHAR(50) NULL,
    `status`      VARCHAR(20) DEFAULT 'active',
    `is_featured` TINYINT(1) DEFAULT 0,
    `deleted_at`  DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 103. SEO تبلیغات (seo_ads)
-- ============================================================
-- این جدول همان ads است با type='seo' - ستون‌های اضافه:
-- keyword, price_per_click, remaining_budget

-- ============================================================
-- 104. لاگ Cron (cron_jobs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `cron_jobs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL,
    `last_run`    DATETIME NULL,
    `next_run`    DATETIME NULL,
    `status`      VARCHAR(20) DEFAULT 'idle' COMMENT 'idle|running|completed|failed',
    `result`      TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 105. تسک‌های سفارشی - جدول تحلیلی (custom_task_analytics)
-- ============================================================
CREATE TABLE IF NOT EXISTS `custom_task_analytics` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id`     INT UNSIGNED NOT NULL,
    `views`       INT DEFAULT 0,
    `submissions` INT DEFAULT 0,
    `approvals`   INT DEFAULT 0,
    `rejections`  INT DEFAULT 0,
    `date`        DATE NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_task_date` (`task_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 106. تراکنش‌های تسک سفارشی (custom_task_transactions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `custom_task_transactions` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id`        INT UNSIGNED NOT NULL,
    `user_id`        INT UNSIGNED NOT NULL,
    `amount`         DECIMAL(20,2) NOT NULL,
    `type`           VARCHAR(20) NOT NULL COMMENT 'reward|refund|fee',
    `transaction_id` VARCHAR(64) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_task_id` (`task_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 107. لاگ سیستم (system_logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `system_logs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `level`       VARCHAR(10) NOT NULL COMMENT 'debug|info|warning|error|critical',
    `channel`     VARCHAR(50) NULL,
    `message`     TEXT NOT NULL,
    `context`     JSON NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_level` (`level`),
    INDEX `idx_channel` (`channel`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 108. گزارش‌های تقلب دستی (fraud_reports) 
-- ============================================================
CREATE TABLE IF NOT EXISTS `fraud_reports` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reporter_id` INT UNSIGNED NOT NULL,
    `target_id`   INT UNSIGNED NOT NULL,
    `reason`      TEXT NOT NULL,
    `evidence`    JSON NULL,
    `status`      VARCHAR(20) DEFAULT 'open',
    `reviewed_by` INT UNSIGNED NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_reporter_id` (`reporter_id`),
    INDEX `idx_target_id` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 109. مدیریت تعلیق حساب (user_suspensions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_suspensions` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `reason`       TEXT NOT NULL,
    `suspended_by` INT UNSIGNED NOT NULL,
    `suspended_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   DATETIME NULL,
    `lifted_at`    DATETIME NULL,
    `lifted_by`    INT UNSIGNED NULL,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STORED PROCEDURE برای پاکسازی متریک‌های Feature Flag
-- ============================================================
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS `sp_cleanup_feature_metrics`(IN days_old INT)
BEGIN
    DELETE FROM `feature_flag_cache` WHERE expires_at < NOW() - INTERVAL days_old DAY;
END$$
DELIMITER ;

-- ============================================================
-- FOREIGN KEYS (اختیاری - برای یکپارچگی داده)
-- ============================================================
-- ALTER TABLE `wallets` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
-- ALTER TABLE `transactions` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT;
-- ALTER TABLE `bank_cards` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT;
-- ALTER TABLE `manual_deposits` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT;
-- ALTER TABLE `withdrawals` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- پایان فایل اسکیما
-- تعداد جداول: 109 جدول
-- ============================================================
