-- ============================================================
-- Migration: Add Cryptographic Hash Chaining to Audit Trail
-- Date: 2026-05-22
-- ============================================================

ALTER TABLE `audit_trail` 
    ADD COLUMN `hash` CHAR(64) NULL COMMENT 'SHA-256 hash of this entry combined with previous entry hash',
    ADD COLUMN `prev_hash` CHAR(64) NULL COMMENT 'SHA-256 hash of the immediately preceding entry';

CREATE INDEX `idx_audit_trail_hash` ON `audit_trail` (`hash`);

DROP TRIGGER IF EXISTS `prevent_audit_trail_update`;
DROP TRIGGER IF EXISTS `prevent_audit_trail_delete`;

CREATE TRIGGER `prevent_audit_trail_update`
BEFORE UPDATE ON `audit_trail`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are immutable and cannot be updated.';
END;

CREATE TRIGGER `prevent_audit_trail_delete`
BEFORE DELETE ON `audit_trail`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are immutable and cannot be deleted.';
END;
