-- اضافه کردن ستون‌های باقی‌مانده برای جدول withdrawals

ALTER TABLE `withdrawals` 
ADD COLUMN `idempotency_key` VARCHAR(255) NULL COMMENT 'کلید Idempotency',
ADD COLUMN `ip_address` VARCHAR(45) NULL COMMENT 'آدرس IP کاربر',
ADD COLUMN `device_fingerprint` VARCHAR(255) NULL COMMENT 'امضای دستگاه',
ADD COLUMN `metadata` LONGTEXT NULL COMMENT 'اطلاعات اضافی JSON';

-- بروزرسانی ایندکس‌ها
ALTER TABLE `withdrawals` 
ADD UNIQUE INDEX `idx_idempotency_key` (`idempotency_key`),
ADD INDEX `idx_ip_address` (`ip_address`);
