-- اضافه کردن ستون‌های جديد برای تراکنش‌های مالی

ALTER TABLE `transactions` 
ADD COLUMN `balance_before` DECIMAL(18, 4) NULL COMMENT 'موجودی قبل از تراکنش',
ADD COLUMN `balance_after` DECIMAL(18, 4) NULL COMMENT 'موجودی بعد از تراکنش',
ADD COLUMN `gateway_transaction_id` VARCHAR(255) NULL COMMENT 'شناسه تراکنش درگاه',
ADD COLUMN `ref_id` VARCHAR(100) NULL COMMENT 'شناسه مرجع (ref_id)',
ADD COLUMN `ref_type` VARCHAR(80) NULL COMMENT 'نوع مرجع (ref_type)';

-- اضافه کردن ایندکس‌ها
ALTER TABLE `transactions` 
ADD INDEX `idx_gateway_transaction_id` (`gateway_transaction_id`),
ADD INDEX `idx_ref_id` (`ref_id`);
