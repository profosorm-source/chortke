CREATE TABLE IF NOT EXISTS `interactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `interactable_type` VARCHAR(255) NOT NULL COMMENT 'مدل پلیمورفیک مثل App\\Models\\InvestmentPlan',
    `interactable_id` BIGINT UNSIGNED NOT NULL COMMENT 'آیدی موجودیت',
    `interaction_type` VARCHAR(50) NOT NULL COMMENT 'favorite, rating, report',
    `context` VARCHAR(50) NOT NULL COMMENT 'module context (e.g. investment, youtube_tasks)',
    `value` INT NULL COMMENT 'مقدار عددی برای ریتینگ (مثلا 1 تا 5)',
    `meta_json` JSON NULL COMMENT 'دیتای اضافی مثل دلیل ریپورت',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    
    -- ارتباط با جدول کاربران
    CONSTRAINT `fk_interactions_user_id` 
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    
    -- جلوگیری از ثبت دو لایک یا دو ریتینگ توسط یک کاربر برای یک موجودیت خاص
    UNIQUE KEY `uk_user_interaction` (`user_id`, `interactable_type`, `interactable_id`, `interaction_type`),
    
    -- ایندکس برای جستجوی سریع روی موجودیت‌ها (گرفتن تمام کامنت‌ها یا لایک‌های یک پست)
    INDEX `idx_interactable` (`interactable_type`, `interactable_id`),
    
    -- ایندکس روی نوع اینتراکشن برای کوئری‌های تجمعی
    INDEX `idx_interaction_type` (`interaction_type`, `context`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول پلیمورفیک یکپارچه برای مدیریت ریتینگ، علاقه‌مندی و گزارشات تخلف';
