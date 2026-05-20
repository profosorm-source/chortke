-- =============================================================================
-- Migration: create_outbox_events
-- Purpose : Transactional Outbox pattern table for OutboxService/OutboxPublisher
--           (Section 8.1 — Critical for financial/event flows)
--
-- Notes:
--   - Records that should be published *after* a successful financial/business
--     transaction are written here in the same DB transaction.
--   - OutboxPublisher reserves & publishes pending rows out-of-band with
--     retry, exponential backoff and DLQ semantics.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `outbox_events` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `aggregate_type`  VARCHAR(80)     NOT NULL,
    `aggregate_id`    VARCHAR(128)    NOT NULL,
    `event_type`      VARCHAR(120)    NOT NULL,
    `payload`         LONGTEXT        NULL,
    `status`          ENUM('pending','processing','published','failed') NOT NULL DEFAULT 'pending',
    `attempts`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error`      VARCHAR(2000)   NULL,
    `available_at`    DATETIME        NOT NULL,
    `published_at`    DATETIME        NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NULL,
    PRIMARY KEY (`id`),
    KEY `ix_outbox_pickup`     (`status`, `available_at`, `attempts`),
    KEY `ix_outbox_aggregate`  (`aggregate_type`, `aggregate_id`),
    KEY `ix_outbox_event_type` (`event_type`, `status`),
    KEY `ix_outbox_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
