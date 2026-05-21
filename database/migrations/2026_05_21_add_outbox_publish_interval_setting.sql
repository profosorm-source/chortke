-- Migration: Add outbox_publish_interval to system_settings (general)
-- Date: 2026-05-21

INSERT INTO system_settings
  (`key`, `value`, `group`, `type`, `description`, `created_at`, `updated_at`)
VALUES
  ('outbox_publish_interval', '60', 'general', 'int', 'مدت زمان (ثانیه) بین هر اجرای OutboxPublisher برای ارسال رویدادهای Outbox به سیستم پیام‌رسان. کاهش این مقدار باعث ارسال سریع‌تر پیام‌ها می‌شود اما بار سرور را افزایش می‌دهد.', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `value`='60',
  `group`='general',
  `type`='int',
  `description`='مدت زمان (ثانیه) بین هر اجرای OutboxPublisher برای ارسال رویدادهای Outbox به سیستم پیام‌رسان. کاهش این مقدار باعث ارسال سریع‌تر پیام‌ها می‌شود اما بار سرور را افزایش می‌دهد.',
  `updated_at`=NOW();
