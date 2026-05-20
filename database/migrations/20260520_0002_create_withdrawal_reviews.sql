-- =============================================================================
-- Migration: create_withdrawal_reviews
-- Purpose : Safe Stuck Withdrawal Review Workflow
--           (Section 8.5 / 8.7 — withdrawal reconciliation hardening)
--
-- Design principles:
--   - Stuck withdrawals are NEVER auto-completed.
--   - They are flagged into a review queue, an admin notification is fired
--     via the outbox, and only *deterministic* auto-fix cases are resolved
--     automatically (e.g. a row stuck in `processing` whose linked
--     transaction is already `failed`/`cancelled` for >X minutes — refund).
--   - All actions leave an immutable audit trail.
--
-- Concurrency:
--   We do NOT add a (withdrawal_id, review_status) UNIQUE key because MySQL
--   cannot express "at most one OPEN row" as a partial index. Instead the
--   service layer atomically checks for an existing open row inside a
--   transaction before inserting a new one (idempotent flagging).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `withdrawal_reviews` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `withdrawal_id`     BIGINT UNSIGNED NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `detected_status`   VARCHAR(32)     NOT NULL,
    `transaction_status` VARCHAR(32)    NULL,
    `stuck_minutes`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `review_status`     ENUM('open','in_progress','auto_resolved','admin_resolved','dismissed') NOT NULL DEFAULT 'open',
    `severity`          ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `reason_code`       VARCHAR(80)     NOT NULL,
    `details`           LONGTEXT        NULL,
    `notified_admin_at` DATETIME        NULL,
    `assigned_admin_id` BIGINT UNSIGNED NULL,
    `resolved_at`       DATETIME        NULL,
    `resolved_by`       BIGINT UNSIGNED NULL,
    `resolution_note`   VARCHAR(1000)   NULL,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NULL,
    PRIMARY KEY (`id`),
    KEY `ix_review_status`           (`review_status`, `created_at`),
    KEY `ix_review_withdrawal`       (`withdrawal_id`, `review_status`),
    KEY `ix_review_user`             (`user_id`),
    KEY `ix_review_severity`         (`severity`, `review_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
