-- Transactional outbox for financial/event flows

CREATE TABLE IF NOT EXISTS outbox_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    aggregate_type VARCHAR(80) NOT NULL,
    aggregate_id VARCHAR(128) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    payload JSON NULL,
    status ENUM('pending','processing','published','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    KEY idx_outbox_status_available (status, available_at, attempts),
    KEY idx_outbox_aggregate (aggregate_type, aggregate_id),
    KEY idx_outbox_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
