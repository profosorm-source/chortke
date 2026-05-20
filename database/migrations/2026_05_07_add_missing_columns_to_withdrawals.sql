-- اضافه کردن ستون fee و سایر ستون‌های مفقود

ALTER TABLE `withdrawals` 
ADD COLUMN `fee` DECIMAL(18, 4) NULL COMMENT 'هزینه برداشت',
ADD COLUMN `final_amount` DECIMAL(18, 4) NULL COMMENT 'مبلغ نهایی بعد از کسر هزینه',
ADD COLUMN `bank_card_id` BIGINT UNSIGNED NULL COMMENT 'شناسه کارت بانکی',
ADD COLUMN `crypto_network` VARCHAR(100) NULL COMMENT 'شبکه رمزنگاری',
ADD COLUMN `crypto_wallet` VARCHAR(300) NULL COMMENT 'آدرس کیف پول';

-- بروزرسانی ایندکس‌ها
ALTER TABLE `withdrawals` 
ADD INDEX `idx_bank_card_id` (`bank_card_id`),
ADD INDEX `idx_crypto_network` (`crypto_network`);
