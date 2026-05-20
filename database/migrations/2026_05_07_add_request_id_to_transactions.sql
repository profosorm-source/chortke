-- اضافه کردن ستون‌های اضافی برای تراکنش‌های مالی

ALTER TABLE `transactions` 
ADD COLUMN `request_id` VARCHAR(100) NULL COMMENT 'شناسه درخواست';

-- بروزرسانی ایندکس‌ها
ALTER TABLE `transactions` 
ADD INDEX `idx_request_id` (`request_id`);
