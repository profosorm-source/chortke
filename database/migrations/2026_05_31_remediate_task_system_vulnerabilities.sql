-- 1. Support unique DAILY executes for SEO tasks
ALTER TABLE seo_executions ADD COLUMN execution_date DATE NULL;
UPDATE seo_executions SET execution_date = DATE(created_at) WHERE execution_date IS NULL;
ALTER TABLE seo_executions MODIFY COLUMN execution_date DATE NOT NULL;
ALTER TABLE seo_executions ADD UNIQUE KEY unique_user_ad_daily (user_id, ad_id, execution_date);

-- 2. Compound performance indexes for feeds
CREATE INDEX idx_exec_lookup ON social_task_executions(ad_id, executor_id, status);
CREATE INDEX idx_custom_sub_lookup ON custom_task_submissions(task_id, worker_id, status);
