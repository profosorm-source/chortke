<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * MagicNumbers - تمام Magic Numbers کو Constants میں تبدیل کریں
 * 
 * یہ فائل تمام hard-coded numbers کو centralize کرتی ہے
 * تاکہ کوڈ maintainable اور readable رہے
 */
class TimeConstants
{
    // ⏱️ وقت کی constants (seconds میں)
    public const SECONDS_PER_MINUTE = 60;
    public const SECONDS_PER_HOUR = 3600;
    public const SECONDS_PER_DAY = 86400;
    public const SECONDS_PER_WEEK = 604800;
    public const SECONDS_PER_MONTH = 2592000;  // 30 days
    public const SECONDS_PER_YEAR = 31536000;  // 365 days

    // ⏱️ منٹوں میں
    public const MINUTES_PER_HOUR = 60;
    public const MINUTES_PER_DAY = 1440;
    public const MINUTES_PER_WEEK = 10080;

    // ⏱️ Decay durations (rate limiting)
    public const DECAY_MINUTES_STANDARD = 60;
    public const DECAY_MINUTES_SHORT = 10;
    public const DECAY_MINUTES_LONG = 120;

    // ⏱️ Timeouts (seconds)
    public const CURL_TIMEOUT_STANDARD = 30;
    public const CURL_TIMEOUT_CONNECT = 10;
    public const CURL_TIMEOUT_SHORT = 5;
    public const CURL_TIMEOUT_LONG = 60;
}

class PercentageConstants
{
    // 💯 Fee percentages
    public const AD_TUBE_FEE_PERCENT = 20;
    public const BANNER_FEE_PERCENT = 12;
    public const SOCIAL_TASK_FEE_PERCENT = 15;
    public const DEFAULT_FEE_PERCENT = 15;

    // 💯 Quality thresholds (in percentages)
    public const QUALITY_EXCELLENT = 90;
    public const QUALITY_GOOD = 75;
    public const QUALITY_AVERAGE = 50;
    public const QUALITY_POOR = 25;
}

class TrustScoreConstants
{
    // 🎯 Initial and boundary values
    public const INITIAL = 50;
    public const MINIMUM = 0;
    public const MAXIMUM = 100;

    // ➕ Positive increments
    public const INCREMENT_GOOD_TASK = 2;
    public const INCREMENT_NATURAL_BEHAVIOR = 1;
    public const INCREMENT_HEALTHY_WEEK = 2;
    public const INCREMENT_VERIFIED_USER = 5;

    // ➖ Negative decrements
    public const DECREMENT_REJECTED = -5;
    public const DECREMENT_SUSPICIOUS = -3;
    public const DECREMENT_SOFT_EXCESS = -2;
    public const DECREMENT_CONFIRMED_FRAUD = -10;
    public const DECREMENT_RAPID_PATTERN = -4;

    // 🔢 Score thresholds
    public const THRESHOLD_HIGH_RISK = 50;
    public const THRESHOLD_MEDIUM_RISK = 40;
    public const THRESHOLD_LOW_RISK = 20;
}

class RateLimitConstants
{
    // 🔒 Login attempts
    public const LOGIN_MAX_ATTEMPTS = 10;
    public const LOGIN_DECAY_MINUTES = 60;

    // 🔐 Password reset
    public const PASSWORD_RESET_MAX_ATTEMPTS = 5;
    public const PASSWORD_RESET_DECAY_MINUTES = 60;

    // 📧 Email verification
    public const EMAIL_VERIFY_MAX_ATTEMPTS = 20;
    public const EMAIL_VERIFY_DECAY_MINUTES = 60;

    // 💳 Payment attempts
    public const PAYMENT_MAX_ATTEMPTS = 30;
    public const PAYMENT_DECAY_MINUTES = 60;

    // 📝 API rate limits
    public const API_MAX_REQUESTS = 100;
    public const API_DECAY_MINUTES = 60;
}

class TaskScoringConstants
{
    // ⭐ Score thresholds
    public const APPROVED_THRESHOLD = 70;
    public const SOFT_APPROVED_THRESHOLD = 40;
    public const REJECTED_THRESHOLD = 20;

    // 🎯 Weights
    public const IP_WEIGHT = 0.35;
    public const SESSION_WEIGHT = 0.25;
    public const MULTI_ACCOUNT_WEIGHT = 0.25;
    public const PATTERN_WEIGHT = 0.15;

    // 📊 Restriction levels
    public const RESTRICTION_HIGH_TASK_RATIO = 0.10;
    public const RESTRICTION_HIGH_REWARD_RATIO = 0.50;

    public const RESTRICTION_MEDIUM_TASK_RATIO = 0.30;
    public const RESTRICTION_MEDIUM_REWARD_RATIO = 0.70;

    public const RESTRICTION_LOW_TASK_RATIO = 0.60;
    public const RESTRICTION_LOW_REWARD_RATIO = 0.90;

    public const RESTRICTION_CLEAN_TASK_RATIO = 1.00;
    public const RESTRICTION_CLEAN_REWARD_RATIO = 1.00;
}

class SystemConstants
{
    // 💾 Storage sizes (bytes)
    public const GB_100 = 107374182400;    // 100GB
    public const GB_40 = 42949672960;      // 40GB
    public const GB_16 = 17179869184;      // 16GB
    public const GB_8 = 8589934592;        // 8GB
    public const GB_1 = 1073741824;        // 1GB
    public const MB_1 = 1048576;           // 1MB

    // 📊 Default system values
    public const DEFAULT_DISK_TOTAL = 107374182400;    // 100GB
    public const DEFAULT_DISK_FREE = 42949672960;      // 40GB
    public const DEFAULT_MEMORY_TOTAL = 17179869184;   // 16GB
    public const DEFAULT_MEMORY_FREE = 8589934592;     // 8GB

    // ⏱️ System monitoring
    public const UPTIME_FORMAT_DAYS = 'روز';
    public const UPTIME_FORMAT_HOURS = 'ساعت';
}

class PaginationConstants
{
    // 📄 Default pagination sizes
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;
    public const MIN_PER_PAGE = 5;

    // 📋 List limits
    public const HISTORY_LIMIT = 20;
    public const METRICS_LIMIT = 30;
    public const CACHE_LIMIT = 50;
}

class RedisConstants
{
    // 🔑 Default SCAN count
    public const SCAN_DEFAULT_COUNT = 100;
    public const SCAN_LARGE_COUNT = 1000;
    public const SCAN_SMALL_COUNT = 50;

    // 🔄 Retry logic
    public const RETRY_MAX_ATTEMPTS = 3;
    public const RETRY_DELAY_MS = 100;
}

class PaymentConstants
{
    // 💰 Amount conversions
    public const RIAL_TO_TOMAN_DIVIDER = 10;    // 1 Toman = 10 Rial
    public const TOMAN_TO_RIAL_MULTIPLIER = 10;

    // ⏱️ Payment timeouts
    public const PAYMENT_VERIFY_TIMEOUT = 30;
    public const PAYMENT_CONNECT_TIMEOUT = 10;

    // 🔄 Retry attempts for payment gateways
    public const PAYMENT_RETRY_ATTEMPTS = 3;
    public const PAYMENT_RETRY_DELAY = 2; // seconds
}

class ValidationConstants
{
    // 📏 Length constraints
    public const EMAIL_MAX_LENGTH = 254;
    public const PASSWORD_MIN_LENGTH = 8;
    public const PASSWORD_MAX_LENGTH = 255;
    public const USERNAME_MIN_LENGTH = 3;
    public const USERNAME_MAX_LENGTH = 50;
    public const PHONE_MIN_LENGTH = 10;
    public const PHONE_MAX_LENGTH = 15;

    // 🔢 Numeric constraints
    public const MIN_POSITIVE_INT = 1;
    public const MAX_INT_ID = 2147483647;  // PHP_INT_MAX for 32-bit
}

class FeatureConstants
{
    // 🏳️ Feature flags history
    public const FEATURE_HISTORY_LIMIT = 20;

    // 🧹 Cleanup periods
    public const CLEANUP_METRICS_DAYS = 30;
}
