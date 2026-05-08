<?php
/**
 * Application Constants
 * 
 * ثابت‌های سراسری برنامه
 */

return [
    // User Status
    'user_status' => [
        'INACTIVE' => 'inactive',
        'ACTIVE' => 'active',
        'SUSPENDED' => 'suspended',
        'BANNED' => 'banned',
    ],
    
    // User Roles
    'user_roles' => [
        'USER' => 'user',
        'SUPPORT' => 'support',
        'ADMIN' => 'admin',
        'SUPER_ADMIN' => 'super_admin',
    ],
    
    // Tier Levels
    'tier_levels' => [
        'SILVER' => 'silver',
        'GOLD' => 'gold',
        'VIP' => 'vip',
    ],
    
    // KYC Status
    'kyc_status' => [
        'PENDING' => 'pending',
        'UNDER_REVIEW' => 'under_review',
        'VERIFIED' => 'verified',
        'REJECTED' => 'rejected',
        'EXPIRED' => 'expired',
    ],
    
    // Transaction Status
    'transaction_status' => [
        'PENDING' => 'pending',
        'PROCESSING' => 'processing',
        'COMPLETED' => 'completed',
        'FAILED' => 'failed',
        'CANCELLED' => 'cancelled',
        'REFUNDED' => 'refunded',
    ],
    
    // Transaction Types
    'transaction_types' => [
        'DEPOSIT' => 'deposit',
        'WITHDRAW' => 'withdraw',
        'TASK_EARNING' => 'task_earning',
        'REFERRAL_COMMISSION' => 'referral_commission',
        'INVESTMENT_PROFIT' => 'investment_profit',
        'INVESTMENT_LOSS' => 'investment_loss',
        'LOTTERY_ENTRY' => 'lottery_entry',
        'LOTTERY_WIN' => 'lottery_win',
        'PENALTY' => 'penalty',
    ],
    
    // Task Status
    'task_status' => [
        'PENDING' => 'pending',
        'IN_PROGRESS' => 'in_progress',
        'COMPLETED' => 'completed',
        'EXPIRED' => 'expired',
        'CANCELLED' => 'cancelled',
        'REJECTED' => 'rejected',
    ],
    
    // Task Types
    'task_types' => [
        'FOLLOW' => 'follow',
        'LIKE' => 'like',
        'COMMENT' => 'comment',
        'SUBSCRIBE' => 'subscribe',
        'VIEW' => 'view',
        'JOIN' => 'join',
        'CUSTOM' => 'custom',
    ],
    
    // Platforms
    'platforms' => [
        'INSTAGRAM' => 'instagram',
        'TELEGRAM' => 'telegram',
        'YOUTUBE' => 'youtube',
        'TWITTER' => 'twitter',
        'TIKTOK' => 'tiktok',
    ],
    
    // Ticket Status
    'ticket_status' => [
        'OPEN' => 'open',
        'IN_PROGRESS' => 'in_progress',
        'ANSWERED' => 'answered',
        'ON_HOLD' => 'on_hold',
        'CLOSED' => 'closed',
    ],
    
    // Investment Status
    'investment_status' => [
        'ACTIVE' => 'active',
        'COMPLETED' => 'completed',
        'CANCELLED' => 'cancelled',
    ],
    
    // Lottery Status
    'lottery_status' => [
        'OPEN' => 'open',
        'VOTING' => 'voting',
        'CLOSED' => 'closed',
        'COMPLETED' => 'completed',
    ],
    
    // Genders
    'genders' => [
        'MALE' => 'male',
        'FEMALE' => 'female',
        'OTHER' => 'other',
    ],
    
    // Limits
    'limits' => [
        'WITHDRAW_MIN' => 50000, // تومان
        'WITHDRAW_MAX' => 50000000,
        'WITHDRAW_PER_DAY' => 1,
        'TASK_PER_HOUR' => 10,
        'REFERRAL_MAX_LEVEL' => 1,
    ],
];