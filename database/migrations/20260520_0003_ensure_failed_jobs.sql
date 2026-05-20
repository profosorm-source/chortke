-- =============================================================================
-- Migration: ensure_failed_jobs
-- Purpose : Defensive creation of the DLQ table used by Core\Queue::fail()
--           and the new retry/purge worker (Section 8.5 / 8.7).
--
-- Notes:
--   - Existing deployments very likely already have this table; CREATE TABLE
--     IF NOT EXISTS is idempotent and safe.
--   - Index on (failed_at) is required by purgeFailedJobsOlderThan().
--   - Index on (queue) is required by retryFailedJobsBatch() and stats().
-- =============================================================================

CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue`      VARCHAR(120)    NOT NULL DEFAULT 'default',
    `payload`    LONGTEXT        NOT NULL,
    `exception`  LONGTEXT        NULL,
    `failed_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_failed_jobs_failed_at` (`failed_at`),
    KEY `ix_failed_jobs_queue`     (`queue`, `failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
