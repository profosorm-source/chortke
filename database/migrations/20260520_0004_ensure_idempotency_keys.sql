-- =============================================================================
-- Migration: ensure_idempotency_keys
-- Purpose : Defensive creation of the central idempotency table used by
--           Core\IdempotencyKey (Section 8.2).
--
-- Notes:
--   - Existing deployments likely already have this table; CREATE TABLE
--     IF NOT EXISTS is idempotent and safe.
--   - UNIQUE (`key`, `user_id`) supports the ON DUPLICATE KEY logic in
--     IdempotencyKey::check() — it's the basis for race-condition-free
--     reservation.
--   - Index on (`status`, `expires_at`) supports the cleanup() loop.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `idempotency_keys` (
    `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `key`           VARCHAR(128)     NOT NULL,
    `user_id`       BIGINT UNSIGNED  NOT NULL,
    `action`        VARCHAR(120)     NOT NULL,
    `status`        ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    `request_data`  LONGTEXT         NULL,
    `result`        LONGTEXT         NULL,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`  DATETIME         NULL,
    `expires_at`    DATETIME         NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_idem_key_user` (`key`, `user_id`),
    KEY `ix_idem_status_expiry` (`status`, `expires_at`),
    KEY `ix_idem_created_at`    (`created_at`),
    KEY `ix_idem_action`        (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
