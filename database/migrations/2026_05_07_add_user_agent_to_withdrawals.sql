-- اضافه کردن ستون user_agent

ALTER TABLE `withdrawals` 
ADD COLUMN `user_agent` TEXT NULL COMMENT 'User Agent مرورگر کاربر';
