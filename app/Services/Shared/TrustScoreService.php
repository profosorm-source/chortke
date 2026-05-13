<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\Score;
use App\Models\SocialTaskAnalyticsModel;
use App\Constants\TrustScoreConstants;
use App\Contracts\LoggerInterface;

class TrustScoreService extends \App\Services\BaseService
{
    private const TRUST_INITIAL = TrustScoreConstants::INITIAL;
    private const TRUST_MIN = TrustScoreConstants::MINIMUM;
    private const TRUST_MAX = TrustScoreConstants::MAXIMUM;

    private const TRUST_INC_GOOD_TASK = TrustScoreConstants::INCREMENT_GOOD_TASK;
    private const TRUST_INC_NATURAL_BEHAVIOR = TrustScoreConstants::INCREMENT_NATURAL_BEHAVIOR;
    private const TRUST_INC_HEALTHY_WEEK = TrustScoreConstants::INCREMENT_HEALTHY_WEEK;

    private const TRUST_DEC_REJECTED = TrustScoreConstants::DECREMENT_REJECTED;
    private const TRUST_DEC_SUSPICIOUS = TrustScoreConstants::DECREMENT_SUSPICIOUS;
    private const TRUST_DEC_SOFT_EXCESS = TrustScoreConstants::DECREMENT_SOFT_EXCESS;
    private const TRUST_DEC_CONFIRMED_FRAUD = TrustScoreConstants::DECREMENT_CONFIRMED_FRAUD;

    public function __construct(
        private Score $scoreModel,
        private SocialTaskAnalyticsModel $socialTaskModel,
        private ScoreEventService $scoreEventService,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function getTrustScore(int $userId): float
    {
        return $this->scoreModel->getTrustScore($userId);
    }

    public function getTrustModifier(int $userId): float
    {
        $trust = $this->getTrustScore($userId);

        if ($trust >= 80) return 10.0;
        if ($trust >= 60) return 5.0;
        if ($trust >= 40) return 0.0;
        if ($trust >= 20) return -5.0;
        return -10.0;
    }

    public function rewardGoodTask(int $userId, int $executionId): void
    {
        $this->applyTrustDelta($userId, self::TRUST_INC_GOOD_TASK, 'good_task', ['execution_id' => $executionId]);
    }

    public function penalizeRejection(int $userId, int $executionId): void
    {
        $this->applyTrustDelta($userId, self::TRUST_DEC_REJECTED, 'rejection', ['execution_id' => $executionId]);
    }

    public function penalizeSuspicious(int $userId, string $reason): void
    {
        $this->applyTrustDelta($userId, self::TRUST_DEC_SUSPICIOUS, 'suspicious_behavior', ['reason' => $reason]);
    }

    public function penalizeSoftExcess(int $userId): void
    {
        $this->applyTrustDelta($userId, self::TRUST_DEC_SOFT_EXCESS, 'soft_approved_excess', []);
    }

    public function penalizeConfirmedFraud(int $userId, string $reason): void
    {
        $this->applyTrustDelta($userId, self::TRUST_DEC_CONFIRMED_FRAUD, 'confirmed_fraud', ['reason' => $reason]);
    }

    public function processWeeklyRecovery(): array
    {
        $updated = 0;
        $checked = 0;
        $users = $this->socialTaskModel->getRecentActiveExecutors(7);

        foreach ($users as $row) {
            $userId = (int)($row->user_id ?? $row['user_id']);
            $checked++;
            $stats = $this->getWeeklyStats($userId);

            if ($stats['rejected'] === 0 && $stats['good_tasks'] >= 5) {
                $this->applyTrustDelta($userId, self::TRUST_INC_HEALTHY_WEEK, 'weekly_recovery', $stats);
                $updated++;
            }
            if ($stats['soft_approved'] >= 3) {
                $this->penalizeSoftExcess($userId);
            }
            $this->saveTrustSnapshot($userId);
        }

        return ['checked' => $checked, 'updated' => $updated];
    }

    public function getWeeklyStats(int $userId): array
    {
        $row = $this->scoreModel->getWeeklyExecutionStats($userId);
        if (!$row) {
            return ['total' => 0, 'good_tasks' => 0, 'rejected' => 0, 'soft_approved' => 0, 'avg_score' => 0];
        }
        return [
            'total' => (int)($row->total ?? 0),
            'good_tasks' => (int)($row->good_tasks ?? 0),
            'rejected' => (int)($row->rejected ?? 0),
            'soft_approved' => (int)($row->soft_approved ?? 0),
            'avg_score' => round((float)($row->avg_score ?? 0), 1),
        ];
    }

    private function applyTrustDelta(int $userId, float $delta, string $source, array $meta): void
    {
        $current = $this->getTrustScore($userId);
        $newVal = $this->clampTrustScore($current + $delta);

        $this->scoreModel->updateTrustScore($userId, $newVal);

        $this->scoreEventService->createEvent(
            $userId,
            'social_trust',
            $source,
            $delta,
            array_merge($meta, [
                'old_trust' => $current,
                'new_trust' => $newVal,
            ])
        );
    }

    private function saveTrustSnapshot(int $userId): void
    {
        $trust = $this->getTrustScore($userId);
        $stats = $this->getWeeklyStats($userId);

        $this->scoreModel->saveTrustSnapshot([
            'user_id' => $userId,
            'trust_score' => $trust,
            'week_good_tasks' => $stats['good_tasks'],
            'week_rejected' => $stats['rejected'],
            'week_soft' => $stats['soft_approved']
        ]);
    }

    private function clampTrustScore(float $val): float
    {
        return max(self::TRUST_MIN, min(self::TRUST_MAX, $val));
    }
}
