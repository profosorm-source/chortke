<?php

/**
 * Migration: ایجاد جدول common_passwords
 * 
 * استفاده:
 * 1. این فایل را در database/migrations کپی کن
 * 2. php migrate.php را اجرا کن
 */

$db = app()->make('database');

// جدول برای 10,000+ پسورد رایج
$db->execute("
    CREATE TABLE IF NOT EXISTS common_passwords (
        id INT PRIMARY KEY AUTO_INCREMENT,
        password_hash CHAR(64) NOT NULL UNIQUE COMMENT 'SHA256 hash of password',
        password_text VARCHAR(255) COMMENT 'Original password (optional, for reference)',
        frequency INT DEFAULT 1 COMMENT 'Times seen in breaches',
        source VARCHAR(100) COMMENT 'Source: HIBP, RockYou, OWASP, etc',
        severity INT DEFAULT 1 COMMENT '1-5: danger level',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        INDEX idx_hash (password_hash),
        INDEX idx_frequency (frequency DESC),
        INDEX idx_severity (severity DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

echo "✅ Table created: common_passwords\n";
echo "📊 Columns:\n";
echo "  - password_hash: SHA256(password)\n";
echo "  - frequency: تعداد دفعات در breaches\n";
echo "  - source: منبع (HIBP, RockYou, etc)\n";
echo "  - severity: سطح خطر (1-5)\n";
