<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Models\Score;
use App\Models\User;
use App\Services\User\UserScoreService;
use App\Services\InfluencerReputationService;
use Core\Cache;
use App\Contracts\LoggerInterface;

/**
 * ScoreService - اورکستریتور اصلی مدیریت امتیازات
 * 
 * این سرویس ورودی واحد برای تمام سیستم‌های امتیازدهی است و وظایف را به سرویس‌های تخصصی هدایت می‌کند.
 */
class ScoreService extends \App\Services\BaseService
{
    private const DOMAIN_FRAUD = 'fraud';
    private const DOMAIN_TASK = 'task';
    private const DOMAIN_SOCIAL_TRUST = 'social_trust';

    private const DOMAIN_TRUST = 'trust';

    private const ALLOWED_ADJUSTMENT_DOMAINS = [
        self::DOMAIN_FRAUD,
        self::DOMAIN_TASK,
        self::DOMAIN_TRUST,
        self::DOMAIN_SOCIAL_TRUST,
    ];

    public function __construct(
        private Database $db,
        protected LoggerInterface $logger,
        private Score $scoreModel,
        private User $userModel,
        private UserScoreService $userScoreService,
        private InfluencerReputationService $influencerReputationService,
        private TrustScoreService $trustScoreService,
        private ScoreEventService $scoreEventService,
        private Cache $cache
    ) {
        parent::__construct($logger);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Score Event Propagation (Delegated to ScoreEventService)
    // ═══════════════════════════════════════════════════════════════════════

    public function addEvent(int $entityId, string $entityType, string $domain, float $delta, string $source, array $meta = []): bool
    {
        return $this->scoreEventService->addEvent($entityId, $entityType, $domain, $delta, $source, $meta);
    }

    public function createEvent(int $userId, string $domain, string $source, float $delta, array $meta = []): bool
    {
        return $this->scoreEventService->recordEvent($userId, $this->normalizeDomain($domain), $source, $delta, $meta);
    }

    public function getTotalScore(int $entityId, string $entityType, string $domain): float
    {
        return $this->scoreEventService->getTotalScore($entityId, $entityType, $domain);
    }

    public function getRecentScoreEvents(int $userId, int $limit = 50): array
    {
        return $this->scoreEventService->getRecentScoreEvents($userId, $limit);
    }

    public function getEventsByUser(int $userId, ?string $domain = null, int $limit = 200): array
    {
        return $this->scoreEventService->getEventsByUser($userId, $domain, $limit);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Trust Score Management (Delegated to TrustScoreService)
    // ═══════════════════════════════════════════════════════════════════════

    public function getTrustScore(int $userId): float
    {
        return $this->trustScoreService->getTrustScore($userId);
    }

    public function getTrustModifier(int $userId): float
    {
        return $this->trustScoreService->getTrustModifier($userId);
    }

    public function rewardGoodTask(int $userId, int $executionId): void
    {
        $this->trustScoreService->rewardGoodTask($userId, $executionId);
    }

    public function penalizeRejection(int $userId, int $executionId): void
    {
        $this->trustScoreService->penalizeRejection($userId, $executionId);
    }

    public function penalizeSuspicious(int $userId, string $reason): void
    {
        $this->trustScoreService->penalizeSuspicious($userId, $reason);
    }

    public function penalizeSoftExcess(int $userId): void
    {
        $this->trustScoreService->penalizeSoftExcess($userId);
    }

    public function penalizeConfirmedFraud(int $userId, string $reason): void
    {
        $this->trustScoreService->penalizeConfirmedFraud($userId, $reason);
    }

    public function processWeeklyRecovery(): array
    {
        return $this->trustScoreService->processWeeklyRecovery();
    }

    public function getWeeklyStats(int $userId): array
    {
        return $this->trustScoreService->getWeeklyStats($userId);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // User Score Logic (Delegated to UserScoreService)
    // ═══════════════════════════════════════════════════════════════════════

    public function applyEventDelta(int $userId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        return $this->userScoreService->applyEventDelta($userId, $this->normalizeDomain($domain), $delta, $source, $meta);
    }

    public function getFraudScore(int $userId): float
    {
        return $this->userScoreService->getFraudScore($userId);
    }

    public function getTaskScore(int $userId): float
    {
        return $this->userScoreService->getTaskScore($userId);
    }

    public function getEffectiveScore(int $userId, string $domain, float $rawScore): float
    {
        return $this->userScoreService->getEffectiveScore($userId, $this->normalizeDomain($domain), $rawScore);
    }

    public function incrementFraudRawScore(int $userId, float $delta, string $source, array $meta = []): bool
    {
        return $this->userScoreService->incrementFraudRawScore($userId, $delta, $source, $meta);
    }

    public function createAdjustment(
        int $userId,
        string $domain,
        string $operation,
        float $value,
        string $reason,
        ?string $expiresAt = null,
        ?int $createdBy = null
    ): array {
        try {
            $domain = $this->normalizeDomain($domain);
            $this->validateAdjustment($domain, $operation, $value, $reason);

            $success = $this->scoreModel->createAdjustment([
                'user_id' => $userId,
                'domain' => $domain,
                'operation' => $operation,
                'value' => $value,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'created_by' => $createdBy ?? 0,
            ]);

            if ($success) {
                $this->createEvent($userId, $domain, 'admin_adjustment', 0, [
                    'operation' => $operation,
                    'value' => $value,
                    'reason' => $reason,
                    'expires_at' => $expiresAt,
                    'admin_id' => $createdBy,
                ]);
                $this->invalidateUserScoreCaches($userId, $domain);
            }

            return [
                'success' => (bool)$success,
                'message' => $success ? 'اصلاح امتیاز ثبت شد.' : 'ثبت اصلاح امتیاز ناموفق بود.',
            ];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Reputation Management (Delegated to InfluencerReputationService)
    // ═══════════════════════════════════════════════════════════════════════

    public function getInfluencerStats(int $profileId): array
    {
        return $this->influencerReputationService->getPublicStats($profileId);
    }

    public function scoreAfterDisputeResolution(object $dispute, string $verdict, string $resolvedBy): void
    {
        $this->influencerReputationService->scoreAfterDisputeResolution($dispute, $verdict, $resolvedBy);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Manual / Admin Adjustments
    // ═══════════════════════════════════════════════════════════════════════

    public function adjust(int $adminId, int $userId, string $domain, string $operation, float $value, string $reason, ?string $expiresAt = null): bool
    {
        $this->assertAdminCanAdjust($adminId, 'perform score adjustments');
        $domain = $this->normalizeDomain($domain);
        $this->validateAdjustment($domain, $operation, $value, $reason);

        $ok = $this->scoreModel->createAdjustment([
            'user_id' => $userId,
            'domain' => $domain,
            'operation' => $operation,
            'value' => $value,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'created_by' => $adminId,
        ]);

        if ($ok) {
            $this->createEvent($userId, $domain, 'admin_adjustment', 0, [
                'operation' => $operation,
                'value' => $value,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'admin_id' => $adminId,
            ]);

            $this->invalidateUserScoreCaches($userId, $domain);

            $this->logInfo('admin.score.adjusted', [
                'admin_id' => $adminId,
                'user_id' => $userId,
                'domain' => $domain,
                'operation' => $operation,
                'value' => $value,
                'expires_at' => $expiresAt
            ]);
        }
        return $ok;
    }

    public function revokeAdjustment(int $adminId, int $adjustmentId, string $reason): bool
    {
        $this->assertAdminCanAdjust($adminId, 'revoke score adjustments');

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Revoke reason is required.');
        }

        // Verify if adjustment actually exists and is currently active
        $adjustment = $this->db->table('user_score_adjustments')
            ->where('id', '=', $adjustmentId)
            ->first();

        if (!$adjustment) {
            throw new \InvalidArgumentException('Adjustment not found.');
        }

        if ((int)$adjustment->is_active === 0) {
            throw new \InvalidArgumentException('Adjustment is already inactive.');
        }

        $ok = $this->scoreModel->revokeAdjustment($adjustmentId, $adminId, $reason);
        
        if ($ok) {
            $userId = (int)$adjustment->user_id;
            $domain = (string)$adjustment->domain;

            $this->invalidateUserScoreCaches($userId, $domain);

            $this->logInfo('admin.score.adjustment_revoked', [
                'admin_id' => $adminId,
                'adjustment_id' => $adjustmentId,
                'user_id' => $userId,
                'domain' => $domain,
                'reason' => $reason
            ]);
        }
        return $ok;
    }

    public function getActiveAdjustments(int $userId, string $domain): array
    {
        return $this->scoreModel->getActiveAdjustments($userId, $this->normalizeDomain($domain));
    }

    private function validateAdjustment(string $domain, string $operation, float $value, string $reason): void
    {
        if (!in_array($this->normalizeDomain($domain), self::ALLOWED_ADJUSTMENT_DOMAINS, true)) {
            throw new \InvalidArgumentException('Invalid score domain.');
        }
        if (!in_array($operation, ['set', 'add', 'subtract'], true)) {
            throw new \InvalidArgumentException('Invalid score operation.');
        }
        // set can legitimately reset a score to 0; add/subtract must be positive deltas.
        if ($operation === 'set' ? $value < 0 : $value <= 0) {
            throw new \InvalidArgumentException('Adjustment value is invalid for the selected operation.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Adjustment reason is required.');
        }
    }

    public function revokeScoreAdjustment(int $adjustmentId, ?int $adminId, string $reason): bool
    {
        if ($adminId === null) {
            throw new \InvalidArgumentException('Admin id is required.');
        }
        return $this->revokeAdjustment($adminId, $adjustmentId, $reason);
    }

    private function normalizeDomain(string $domain): string
    {
        // Keep module score domains isolated. Do not globally map "trust" to
        // "social_trust" because other modules may define their own trust semantics.
        return strtolower(trim($domain));
    }

    private function assertAdminCanAdjust(int $adminId, string $action): void
    {
        $adminUser = $this->userModel->findById($adminId);
        $role = (string)($adminUser->role ?? '');
        if (!$adminUser || !in_array($role, ['admin', 'superadmin', 'super_admin'], true)) {
            throw new \InvalidArgumentException("Unauthorized action: Only administrators can {$action}.");
        }
    }

    private function invalidateUserScoreCaches(int $userId, string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        $this->cache->forget("user_dashboard_stats:{$userId}");
        $this->cache->forget("user_score:{$userId}:{$domain}");
        $this->cache->forget("temp_{$domain}_score:{$userId}");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Direct Model Helpers
    // ═══════════════════════════════════════════════════════════════════════

    public function getUserForScoreManagement(int $userId): ?object
    {
        return $this->userModel->findById($userId);
    }

    public function getTaskRawRisk(int $userId): float
    {
        return $this->scoreModel->getTaskRawRisk($userId);
    }
}
