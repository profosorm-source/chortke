-- Ledger reconciliation integrity indexes

SET @table_exists := (
    SELECT COUNT(1) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
);

SET @sql := IF(@table_exists = 1,
    'DELETE le1 FROM ledger_entries le1 JOIN ledger_entries le2 ON le1.transaction_id = le2.transaction_id AND le1.account = le2.account AND le1.currency = le2.currency AND le1.id > le2.id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ledger_entries'
      AND INDEX_NAME = 'uq_ledger_transaction_account_currency'
);
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE ledger_entries ADD UNIQUE KEY uq_ledger_transaction_account_currency (transaction_id, account, currency)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ledger_entries'
      AND INDEX_NAME = 'idx_ledger_account_currency_created'
);
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE ledger_entries ADD INDEX idx_ledger_account_currency_created (account, currency, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ledger_entries'
      AND INDEX_NAME = 'idx_ledger_transaction_currency'
);
SET @sql := IF(@table_exists = 1 AND @idx_exists = 0,
    'ALTER TABLE ledger_entries ADD INDEX idx_ledger_transaction_currency (transaction_id, currency)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
