-- =========================================================================
-- Migration: Add Missing Search and Performance Indexes
-- Description: Adds FULLTEXT indexes to fix the AdminSearchGateway 
--              LIKE '%term%' bottleneck and adds missing B-Tree indexes.
-- =========================================================================

-- 1. FULLTEXT Indexes for AdminSearchGateway
-- These allow AdminSearchGateway to use MATCH AGAINST instead of LIKE %...%
ALTER TABLE `users` ADD FULLTEXT INDEX `ft_users_search` (`full_name`, `email`, `mobile`, `username`);
ALTER TABLE `transactions` ADD FULLTEXT INDEX `ft_transactions_search` (`transaction_id`, `description`, `gateway_transaction_id`, `ref_id`, `idempotency_key`);
ALTER TABLE `tickets` ADD FULLTEXT INDEX `ft_tickets_search` (`subject`);
ALTER TABLE `withdrawals` ADD FULLTEXT INDEX `ft_withdrawals_search` (`tracking_code`, `transaction_id`);
ALTER TABLE `manual_deposits` ADD FULLTEXT INDEX `ft_manual_deposits_search` (`tracking_code`, `transaction_id`);
ALTER TABLE `crypto_deposits` ADD FULLTEXT INDEX `ft_crypto_deposits_search` (`tx_hash`, `transaction_id`);
ALTER TABLE `ads` ADD FULLTEXT INDEX `ft_ads_search` (`title`, `description`, `keyword`);

-- 2. Missing Database Indexes (B-Tree)
-- transactions(user_id, created_at) was already created in an earlier migration (idx_transactions_user_created)
-- notifications(user_id, is_read, created_at) was already created in an earlier migration
-- score_events(entity_type, entity_id, domain, created_at) was already created in an earlier migration

-- Add tickets missing index
ALTER TABLE `tickets` ADD INDEX `idx_tickets_user_status_updated` (`user_id`, `status`, `updated_at`);

-- Add interactions missing index
ALTER TABLE `interactions` ADD INDEX `idx_interactions_type` (`interactable_type`, `interactable_id`, `interaction_type`);
