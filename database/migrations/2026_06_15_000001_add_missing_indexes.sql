-- Migration: Add Missing Compound Indexes for Performance

-- 1. direct_messages: sender_id + receiver_id + created_at (Conversation Listing)
ALTER TABLE `direct_messages` ADD INDEX `idx_sender_receiver_created` (`sender_id`, `receiver_id`, `created_at`);

-- 2. escrows: order_id + order_type (Fixed Escrow Lookup)
ALTER TABLE `escrows` ADD INDEX `idx_order_id_type` (`order_id`, `order_type`);

-- 3. story_orders: influencer_user_id + status (Influencer Dashboard Queries)
ALTER TABLE `story_orders` ADD INDEX `idx_influencer_status` (`influencer_user_id`, `status`);
