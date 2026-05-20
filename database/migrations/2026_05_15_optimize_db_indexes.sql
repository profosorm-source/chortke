-- ارتقای پرفورمنس و بهینه‌سازی ایندکس‌های پایگاه‌داده چرتکه
-- UPG-05: بهینه‌سازی سرعت صف، تراکنش‌ها و سیستم ضدتقلب

-- ۱. بهینه‌سازی جدول queues (افزودن فیلد مرتب‌سازی به ایندکس پوششی جهت کاهش چشمگیر I/O دیسک)
ALTER TABLE `queues` DROP INDEX `queues_queue_reserved_at_available_at_index`;
ALTER TABLE `queues` ADD INDEX `idx_queues_pop_optimized` (`queue`, `reserved_at`, `available_at`, `created_at`);

-- ۲. بهینه‌سازی جدول transactions (ایندکس‌های ترکیبی برای لود آنی تراکنش‌های کاربر و ادمین بر اساس زمان)
ALTER TABLE `transactions` ADD INDEX `idx_transactions_user_created` (`user_id`, `created_at`);
ALTER TABLE `transactions` ADD INDEX `idx_transactions_status_created` (`status`, `created_at`);

-- ۳. بهینه‌سازی جدول fraud_logs (ایندکس‌های ترکیبی زمانی جهت تسریع مانیتورینگ تحلیلی ضدتقلب)
ALTER TABLE `fraud_logs` ADD INDEX `idx_fraud_user_created` (`user_id`, `created_at`);
ALTER TABLE `fraud_logs` ADD INDEX `idx_fraud_type_created` (`fraud_type`, `created_at`);
