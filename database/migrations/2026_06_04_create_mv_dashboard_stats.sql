CREATE TABLE IF NOT EXISTS mv_dashboard_stats (
    currency VARCHAR(10) NOT NULL,
    total_deposits DECIMAL(20,8) DEFAULT 0,
    total_withdrawals DECIMAL(20,8) DEFAULT 0,
    today_deposits DECIMAL(20,8) DEFAULT 0,
    today_withdrawals DECIMAL(20,8) DEFAULT 0,
    pending_transactions INT DEFAULT 0,
    site_revenue DECIMAL(20,8) DEFAULT 0,
    today_revenue DECIMAL(20,8) DEFAULT 0,
    weekly_revenue DECIMAL(20,8) DEFAULT 0,
    monthly_revenue DECIMAL(20,8) DEFAULT 0,
    total_transactions INT DEFAULT 0,
    active_users INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
