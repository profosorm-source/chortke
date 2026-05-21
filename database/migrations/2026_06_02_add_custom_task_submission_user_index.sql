-- بهینه‌سازی عملکرد جستجوی تسک‌های سفارشی برای کاربران
-- افزودن ایندکس روی user_id جهت جلوگیری از اسکن کامل جدول هنگام فیلتر بر اساس کاربر

ALTER TABLE `custom_task_submissions` ADD INDEX `idx_custom_sub_user_id` (`user_id`);
