-- بهینه‌سازی عملکرد فید تسک‌ها و رفع کندی بازپرس‌و‌جوها
-- UPG-06: افزودن ایندکس‌های ترکیبی برای جلوگیری از Full Table Scan در زمان فیلترینگ تسک‌های انجام نشده

-- ۱. ایندکس برای تسک‌های شبکه‌های اجتماعی
ALTER TABLE `social_task_executions` ADD INDEX `idx_social_exec_ad_user` (`ad_id`, `executor_id`, `status`);

-- ۲. ایندکس برای تسک‌های سئو
ALTER TABLE `seo_executions` ADD INDEX `idx_seo_exec_ad_user` (`ad_id`, `user_id`, `status`);

-- ۳. ایندکس برای تسک‌های سفارشی (Custom Tasks)
ALTER TABLE `custom_task_submissions` ADD INDEX `idx_custom_sub_task_worker` (`task_id`, `worker_id`, `status`);
