<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;

/**
 * StateMachineService - State machine enforcement for all modules
 * ✅ Prevents invalid state transitions
 * ✅ Validates business logic constraints
 * ✅ Ensures data consistency
 */
class StateMachineService extends \App\Services\BaseService
{
    /**
     * SocialAd valid state transitions
     */
    private const SOCIAL_AD_TRANSITIONS = [
        'pending'   => ['active', 'rejected'],
        'active'    => ['paused', 'cancelled'],
        'paused'    => ['active', 'cancelled'],
        'cancelled' => [],
        'rejected'  => [],
    ];

    /**
     * VitrineListing state machine
     */
    private const VITRINE_TRANSITIONS = [
        'pending'   => ['active', 'rejected'],
        'active'    => ['in_escrow', 'cancelled'],
        'in_escrow' => ['sold', 'disputed', 'cancelled'],
        'disputed'  => ['sold', 'cancelled'],
        'sold'      => [],
        'rejected'  => [],
        'cancelled' => [],
    ];

    /**
     * Influencer state machine
     */
    private const INFLUENCER_TRANSITIONS = [
        'pending'    => ['verified', 'rejected'],
        'verified'   => ['suspended'],
        'suspended'  => ['verified'],
        'rejected'   => ['pending'],
    ];

    /**
     * Dispute state machine
     */
    private const DISPUTE_TRANSITIONS = [
        'open'         => ['under_review', 'closed'],
        'under_review' => ['resolved'],
        'resolved'     => ['appealed'],
        'appealed'     => ['under_review', 'resolved'],
        'closed'       => [],
    ];

    /**
     * Withdrawal state machine (Critical Financial Flow)
     */
    private const WITHDRAWAL_TRANSITIONS = [
        'pending'    => ['processing', 'rejected', 'cancelled'],
        'processing' => ['completed', 'rejected', 'cancelled'],
        'completed'  => [],
        'rejected'   => [],
        'cancelled'  => [],
    ];

    /**
     * KYC state machine (Compliance and Trust)
     */
    private const KYC_TRANSITIONS = [
        'pending'      => ['under_review', 'rejected'],
        'under_review' => ['verified', 'rejected'],
        'verified'     => ['suspended', 'expired'],
        'suspended'    => ['verified', 'rejected'],
        'rejected'     => ['pending'],
        'expired'      => ['pending'],
    ];

    /**
     * Lottery state machine
     */
    private const LOTTERY_TRANSITIONS = [
        'upcoming'  => ['active', 'cancelled'],
        'active'    => ['drawing', 'cancelled'],
        'drawing'   => ['finished'],
        'finished'  => [],
        'cancelled' => [],
    ];

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public Universal API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generic dynamic state validator for ANY supported entity
     */
    public function canTransition(string $entity, string $currentStatus, string $newStatus): bool
    {
        $allowed = $this->getAllowedTransitions($entity, $currentStatus);
        $valid = in_array($newStatus, $allowed, true);

        if (!$valid) {
            $this->logger->warning('state_transition_violation', [
                'entity'  => $entity,
                'from'    => $currentStatus,
                'to'      => $newStatus,
                'allowed' => $allowed
            ]);
        }

        return $valid;
    }

    /**
     * Retain BC specific method for SocialAd
     */
    public function canTransitionSocialAd(string $currentStatus, string $newStatus): bool
    {
        return $this->canTransition('social_ad', $currentStatus, $newStatus);
    }

    /**
     * Retain BC specific method for Vitrine
     */
    public function canTransitionVitrine(string $currentStatus, string $newStatus): bool
    {
        return $this->canTransition('vitrine_listing', $currentStatus, $newStatus);
    }

    /**
     * Retain BC specific method for Influencer
     */
    public function canTransitionInfluencer(string $currentStatus, string $newStatus): bool
    {
        return $this->canTransition('influencer_profile', $currentStatus, $newStatus);
    }

    /**
     * Retain BC specific method for Dispute
     */
    public function canTransitionDispute(string $currentStatus, string $newStatus): bool
    {
        return $this->canTransition('dispute', $currentStatus, $newStatus);
    }

    /**
     * Get allowed next states
     */
    public function getAllowedTransitions(string $entity, string $currentStatus): array
    {
        return match($entity) {
            'social_ad'            => self::SOCIAL_AD_TRANSITIONS[$currentStatus] ?? [],
            'vitrine_listing'      => self::VITRINE_TRANSITIONS[$currentStatus] ?? [],
            'influencer_profile'   => self::INFLUENCER_TRANSITIONS[$currentStatus] ?? [],
            'dispute'              => self::DISPUTE_TRANSITIONS[$currentStatus] ?? [],
            'withdrawal'           => self::WITHDRAWAL_TRANSITIONS[$currentStatus] ?? [],
            'kyc'                  => self::KYC_TRANSITIONS[$currentStatus] ?? [],
            'lottery'              => self::LOTTERY_TRANSITIONS[$currentStatus] ?? [],
            default                => []
        };
    }

    /**
     * Is state terminal (no further transitions allowed)?
     */
    public function isTerminalState(string $entity, string $status): bool
    {
        $transitions = $this->getAllowedTransitions($entity, $status);
        return empty($transitions);
    }
}
