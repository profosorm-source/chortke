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
class StateMachineServiceextends \App\Services\BaseService
{
    // ─────────────────────────────────────────────────────────────────────────
    // SocialAd State Machine
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * SocialAd valid state transitions
     * pending → active (on approval)
     * active → paused (on admin pause)
     * paused → active (on admin resume)
     * active → cancelled (on admin cancel or advertiser cancel)
     * cancelled → deleted (after cleanup)
     * rejected → deleted (after cleanup)
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
     * pending → active (on approval)
     * active → in_escrow (when buyer accepts)
     * in_escrow → sold (on release)
     * in_escrow → disputed (on dispute)
     * disputed → sold (after resolution)
     * disputed → cancelled (on refund)
     * pending → rejected (on admin reject)
     * active → cancelled (on seller cancel)
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
     * pending → verified (on approval)
     * verified → suspended (on violation)
     * suspended → verified (on appeal approved)
     * pending → rejected (on admin reject)
     * rejected → pending (on resubmission)
     */
    private const INFLUENCER_TRANSITIONS = [
        'pending'    => ['verified', 'rejected'],
        'verified'   => ['suspended'],
        'suspended'  => ['verified'],
        'rejected'   => ['pending'],
    ];

    /**
     * Dispute state machine
     * open → under_review (assigned to moderator)
     * under_review → resolved (decision made)
     * resolved → appealed (on appeal request)
     * appealed → under_review (re-reviewed)
     * open → closed (on cancellation)
     */
    private const DISPUTE_TRANSITIONS = [
        'open'         => ['under_review', 'closed'],
        'under_review' => ['resolved'],
        'resolved'     => ['appealed'],
        'appealed'     => ['under_review', 'resolved'],
        'closed'       => [],
    ];

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate SocialAd state transition
     */
    public function canTransitionSocialAd(string $currentStatus, string $newStatus): bool
    {
        $valid = isset(self::SOCIAL_AD_TRANSITIONS[$currentStatus])
            && in_array($newStatus, self::SOCIAL_AD_TRANSITIONS[$currentStatus], true);

        if (!$valid) {
            $this->logger->warning('invalid_state_transition', [
                'entity' => 'social_ad',
                'from' => $currentStatus,
                'to' => $newStatus,
            ]);
        }

        return $valid;
    }

    /**
     * Validate VitrineListing state transition
     */
    public function canTransitionVitrine(string $currentStatus, string $newStatus): bool
    {
        $valid = isset(self::VITRINE_TRANSITIONS[$currentStatus])
            && in_array($newStatus, self::VITRINE_TRANSITIONS[$currentStatus], true);

        if (!$valid) {
            $this->logger->warning('invalid_state_transition', [
                'entity' => 'vitrine_listing',
                'from' => $currentStatus,
                'to' => $newStatus,
            ]);
        }

        return $valid;
    }

    /**
     * Validate InfluencerProfile state transition
     */
    public function canTransitionInfluencer(string $currentStatus, string $newStatus): bool
    {
        $valid = isset(self::INFLUENCER_TRANSITIONS[$currentStatus])
            && in_array($newStatus, self::INFLUENCER_TRANSITIONS[$currentStatus], true);

        if (!$valid) {
            $this->logger->warning('invalid_state_transition', [
                'entity' => 'influencer_profile',
                'from' => $currentStatus,
                'to' => $newStatus,
            ]);
        }

        return $valid;
    }

    /**
     * Validate Dispute state transition
     */
    public function canTransitionDispute(string $currentStatus, string $newStatus): bool
    {
        $valid = isset(self::DISPUTE_TRANSITIONS[$currentStatus])
            && in_array($newStatus, self::DISPUTE_TRANSITIONS[$currentStatus], true);

        if (!$valid) {
            $this->logger->warning('invalid_state_transition', [
                'entity' => 'dispute',
                'from' => $currentStatus,
                'to' => $newStatus,
            ]);
        }

        return $valid;
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

