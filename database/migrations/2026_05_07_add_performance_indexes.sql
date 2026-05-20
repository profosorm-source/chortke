-- ====================================================================
-- Database Migration: Add Performance Indexes (Phase 4: Optimization)
-- Date: 2026-05-07
-- Description: Adds optimal indexes to highly filtered columns and foreign
--              keys in high-traffic tables to prevent full table scans and
--              maximize JOIN/Aggregation performance.
-- ====================================================================

-- 1. Optimized Indexes for USERS Table
-- Column 'role_id': Crucial for role indexing, user counts per role, and RBAC JOINs.
-- Column 'deleted_at': Crucial for soft-deletes filtering across nearly all user queries.
CREATE INDEX idx_users_role_deleted ON users (role_id, deleted_at);
CREATE INDEX idx_users_email ON users (email);
CREATE INDEX idx_users_created_at ON users (created_at);

-- 2. Optimized Indexes for LOTTERY_PARTICIPATIONS Table
-- Column 'round_id', 'is_deleted': Crucial for batch participation counting and aggregations (GROUP BY).
-- Column 'user_id': Crucial for fast unique participation lookups per round.
CREATE INDEX idx_lottery_participations_round_deleted ON lottery_participations (round_id, is_deleted);
CREATE INDEX idx_lottery_participations_user_deleted ON lottery_participations (user_id, is_deleted);

-- 3. Optimized Indexes for ACCOUNT_DELETION_LOGS Table
-- Column 'status': Crucial for pending vs completed deletion filters.
-- Column 'user_id': Crucial for user lookup in logs.
CREATE INDEX idx_account_deletion_logs_status ON account_deletion_logs (status);
CREATE INDEX idx_account_deletion_logs_user ON account_deletion_logs (user_id);

-- 4. Optimized Indexes for TASK_RATINGS Table
-- Column 'rated_user_id', 'rating_type': Crucial for average rating and trust score calculations.
CREATE INDEX idx_task_ratings_user_type ON task_ratings (rated_user_id, rating_type);
CREATE INDEX idx_task_ratings_task ON task_ratings (task_id);

-- 5. Optimized Indexes for REFERRAL_COMMISSIONS Table
-- Column 'referrer_id', 'status': Crucial for calculating referral quality scores and payouts.
CREATE INDEX idx_referral_commissions_referrer_status ON referral_commissions (referrer_id, status);
