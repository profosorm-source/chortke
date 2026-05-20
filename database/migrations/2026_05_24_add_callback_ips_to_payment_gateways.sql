-- Migration to add callback_ips column to payment_gateways table
ALTER TABLE payment_gateways ADD COLUMN callback_ips JSON NULL COMMENT 'Whitelist IPs for callback validation' AFTER is_test_mode;
