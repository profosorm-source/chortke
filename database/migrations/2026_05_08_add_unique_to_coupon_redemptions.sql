-- Add UNIQUE constraint to prevent multiple coupon redemptions by the same user
ALTER TABLE `coupon_redemptions` ADD UNIQUE KEY `unique_user_coupon` (`user_id`, `coupon_id`);
