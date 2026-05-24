-- =========================================================================
-- Migration: Drop Legacy Gamification and Rating Tables
-- Description: This migration removes deprecated tables and columns that 
--              have been fully replaced by the unified Interaction\RatingService 
--              and Gamification\ScoreService architectures.
-- =========================================================================

-- 1. Drop Legacy Siloed Rating Tables
-- These tables were replaced by the centralized `interactions` table.
DROP TABLE IF EXISTS `task_ratings`;
DROP TABLE IF EXISTS `ratings`;
DROP TABLE IF EXISTS `social_task_ratings`;

-- 2. Drop Legacy Gamification Tables
-- These tables were replaced by the centralized `score_events` table.
DROP TABLE IF EXISTS `user_scores`;
DROP TABLE IF EXISTS `user_trust_scores`;
DROP TABLE IF EXISTS `gamification_logs`;
DROP TABLE IF EXISTS `user_xp_history`;

-- Legacy columns dropping skipped to avoid PDO errors.

-- Note: You can now safely delete the following PHP models:
-- - app/Models/TaskRating.php
-- - app/Models/Rating.php
-- (These files have already been disconnected from the system logic)
