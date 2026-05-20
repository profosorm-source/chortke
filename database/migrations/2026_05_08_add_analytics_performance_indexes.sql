-- Add indexes to prevent full table scans in transaction volume and analytics charts
CREATE INDEX idx_deposits_status_created ON deposits(status, created_at);
CREATE INDEX idx_withdrawals_status_created ON withdrawals(status, created_at);
CREATE INDEX idx_payments_status_created ON payments(status, created_at);
