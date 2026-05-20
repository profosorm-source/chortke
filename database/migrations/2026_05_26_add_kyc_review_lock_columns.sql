-- Chortke Migration Patch
-- Add under_review_by and review_started_at to kyc_verifications for Optimistic Locking

ALTER TABLE kyc_verifications ADD COLUMN under_review_by INT(10) UNSIGNED NULL AFTER rejection_reason;
ALTER TABLE kyc_verifications ADD COLUMN review_started_at TIMESTAMP NULL AFTER under_review_by;
