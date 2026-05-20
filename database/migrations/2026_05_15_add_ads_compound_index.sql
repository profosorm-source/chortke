-- ====================================================================
-- Database Migration: Add Ads Compound Performance Index
-- Date: 2026-05-15
-- Description: Adds a compound index on ads (type, status, remaining_budget)
--              to optimize performance for the background cron jobs.
-- ====================================================================

CREATE INDEX idx_ads_type_status_budget ON ads (type, status, remaining_budget);
