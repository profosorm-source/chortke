-- ساخت جدول برای Dead Letter Queue (DLQ)
-- وظایف شکست خورده پس از حداکثر تلاش به این جدول منتقل می‌شوند

CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `queue` VARCHAR(255) NOT NULL COMMENT 'نام صف',
    `payload` LONGTEXT NOT NULL COMMENT 'داده JSON اصلی تسک',
    `exception` LONGTEXT NOT NULL COMMENT 'جزییات استثنا و تریس سیستم',
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    KEY `idx_queue` (`queue`),
    KEY `idx_failed_at` (`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Dead Letter Queue (DLQ) - جدول وظایف شکست خورده نهایی';
