-- Score / Rating integrity hardening
-- - one rating per (rater, ref)
-- - one score projection per (user, domain)
-- - trust projection one row per user
-- - fast ledger/projection lookups

SET @table_exists := (
    SELECT COUNT(1) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratings'
);
SET @sql := IF(@table_exists = 1,
    'DELETE r1 FROM ratings r1 JOIN ratings r2 ON r1.rater_id = r2.rater_id AND r1.ref_type = r2.ref_type AND r1.ref_id = r2.ref_id AND r1.id > r2.id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ratings'
      AND INDEX_NAME = 'uq_rating_once'
);
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE ratings ADD UNIQUE KEY uq_rating_once (rater_id, ref_type, ref_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(1) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_scores'
);
SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_scores'
      AND INDEX_NAME = 'uq_user_scores_user_domain'
);
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE user_scores ADD UNIQUE KEY uq_user_scores_user_domain (user_id, domain)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(1) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'score_events'
);
SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'score_events'
      AND INDEX_NAME = 'idx_score_events_entity_domain_created'
);
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE score_events ADD INDEX idx_score_events_entity_domain_created (entity_type, entity_id, domain, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(1) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_trust_scores'
);
SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_trust_scores'
      AND INDEX_NAME = 'uq_user_trust_scores_user'
);
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE user_trust_scores ADD UNIQUE KEY uq_user_trust_scores_user (user_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(1) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_score_adjustments'
);
SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_score_adjustments'
      AND INDEX_NAME = 'idx_user_score_adjustments_user_domain_active'
);
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE user_score_adjustments ADD INDEX idx_user_score_adjustments_user_domain_active (user_id, domain, is_active, expires_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
