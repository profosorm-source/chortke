-- اضافه کردن ستون deleted_at برای soft delete

ALTER TABLE `bank_cards` 
ADD COLUMN `deleted_at` TIMESTAMP NULL COMMENT 'تاریخ حذف نرم';

-- اضافه کردن ایندکس
ALTER TABLE `bank_cards` 
ADD INDEX `idx_deleted_at` (`deleted_at`);
