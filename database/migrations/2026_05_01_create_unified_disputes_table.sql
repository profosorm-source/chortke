CREATE TABLE IF NOT EXISTS `disputes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ref_type` varchar(50) NOT NULL,
  `ref_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL COMMENT 'The user who opened the dispute',
  `target_user_id` int(10) unsigned DEFAULT NULL COMMENT 'The other party in the dispute',
  `reason` text NOT NULL,
  `evidence_image` varchar(500) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'open',
  `admin_decision` text DEFAULT NULL,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `penalty_amount` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `penalty_target` varchar(50) DEFAULT NULL,
  `penalty_currency` varchar(50) NOT NULL DEFAULT 'irt',
  `site_tax_amount` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `refund_percent` tinyint(3) unsigned DEFAULT NULL,
  `peer_deadline` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ref_type_ref_id` (`ref_type`,`ref_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dispute_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dispute_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `message` text NOT NULL,
  `attachment` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dispute_id` (`dispute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate data from task_disputes if it exists and disputes is empty
INSERT IGNORE INTO `disputes` (id, ref_type, ref_id, user_id, reason, evidence_image, status, admin_decision, admin_id, penalty_amount, penalty_target, penalty_currency, site_tax_amount, resolved_at, created_at, updated_at)
SELECT id, 'task', IFNULL(submission_id, execution_id), opener_id, reason, evidence_image, status, admin_decision, admin_id, penalty_amount, penalty_target, penalty_currency, site_tax_amount, resolved_at, created_at, updated_at
FROM `task_disputes`;
