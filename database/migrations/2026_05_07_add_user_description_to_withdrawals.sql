-- اضافه کردن ستون user_description و سایر ستون‌های مفقود

ALTER TABLE `withdrawals` 
ADD COLUMN `user_description` TEXT NULL COMMENT 'توضیحات کاربر';
