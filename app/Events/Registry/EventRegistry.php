<?php

declare(strict_types=1);

namespace App\Events\Registry;

/**
 * EventRegistry
 * 
 * Defines standardized domain event names across the application.
 * Replaces hardcoded string-based events (like 'wallet.deposit.requested')
 * with context-aware Domain Events to enable true EDA and Choreography.
 */
class EventRegistry
{
    // Content & Marketing
    public const CONTENT_REVENUE_GENERATED = 'content.revenue.generated';
    public const BANNER_REVENUE_GENERATED  = 'banner.revenue.generated';
    
    // Finance & Crypto
    public const CRYPTO_DEPOSIT_CONFIRMED  = 'crypto.deposit.confirmed';
    public const GATEWAY_PAYMENT_SUCCESS   = 'gateway.payment.success';

    // Social & Influencers
    public const CUSTOM_TASK_VERIFIED      = 'custom_task.verified';
    public const CUSTOM_TASK_REFUNDED      = 'custom_task.refunded';
    public const INFLUENCER_ORDER_COMPLETED = 'influencer_order.completed';
    public const INFLUENCER_ORDER_REFUNDED  = 'influencer_order.refunded';
    public const INFLUENCER_ORDER_PARTIAL_REFUNDED = 'influencer_order.partial_refunded';
    
    // Investment & Escrow
    public const INVESTMENT_MATURED        = 'investment.matured';
    public const ESCROW_RELEASED           = 'escrow.released';
    
    // Gamification & Prediction
    public const LOTTERY_ROUND_FINISHED    = 'lottery_round.finished';
    public const LOTTERY_ROUND_CANCELLED   = 'lottery_round.cancelled';
    public const PREDICTION_BET_WON        = 'prediction_bet.won';
    public const PREDICTION_BET_REFUNDED   = 'prediction_bet.refunded';
    
    // Moderation & Users
    public const DISPUTE_RESOLVED_REFUND   = 'dispute.resolved.refund';
    public const REFERRAL_COMMISSION_EARNED= 'referral.commission.earned';
    
    // Legacy Events (To be deprecated)
    public const WALLET_DEPOSIT_REQUESTED  = 'wallet.deposit.requested';

    /**
     * Get all financial events that might trigger a wallet deposit.
     *
     * Note: This returns specific event names for strict mappings.
     * For forward-compatibility, also provide wildcard patterns via
     * `getDepositTriggerPatterns()` so newly added names under
     * common namespaces are handled without editing this Registry.
     */
    public static function getDepositTriggerEvents(): array
    {
        return [
            self::CONTENT_REVENUE_GENERATED,
            self::BANNER_REVENUE_GENERATED,
            self::CRYPTO_DEPOSIT_CONFIRMED,
            self::GATEWAY_PAYMENT_SUCCESS,
            self::CUSTOM_TASK_VERIFIED,
            self::CUSTOM_TASK_REFUNDED,
            self::INFLUENCER_ORDER_COMPLETED,
            self::INFLUENCER_ORDER_REFUNDED,
            self::INFLUENCER_ORDER_PARTIAL_REFUNDED,
            self::INVESTMENT_MATURED,
            self::ESCROW_RELEASED,
            self::LOTTERY_ROUND_FINISHED,
            self::PREDICTION_BET_WON,
            self::DISPUTE_RESOLVED_REFUND,
            self::REFERRAL_COMMISSION_EARNED,
            // Keeping legacy for any missed spots
            self::WALLET_DEPOSIT_REQUESTED,
        ];
    }

    /**
     * Get wildcard patterns for deposit-triggering events.
     * Patterns use shell-style wildcards compatible with `fnmatch()`.
     * Add broad namespace patterns so new events under these namespaces
     * are captured automatically without editing the registry.
     */
    public static function getDepositTriggerPatterns(): array
    {
        return [
            'wallet.*',               // any wallet-related event
            'crypto.*',               // crypto deposit/confirm events
            'gateway.*',              // payment gateways
            '*.revenue.*',            // content/banner revenue events
            'influencer_order.*',     // influencer order lifecycle
            'custom_task.*',          // custom task rewards/refunds
            'investment.*',
            'escrow.*',
            'lottery_*',              // lottery namespace variations
            'prediction_*',
            'referral.*',
        ];
    }
}

