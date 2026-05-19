<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\AuditTrail;
use App\Services\Notification\NotificationService;
use App\Models\SocialTaskExecutionModel;
use App\Services\SettingService;

use App\Contracts\LoggerInterface;
/**
 * SilentAntiFraudService
 *
 * تصمیم‌گیری نامحسوس (Silent Anti-Fraud)
 */
class SilentAntiFraudService extends \App\Services\BaseService
{
    private const DEFAULT_RESTRICTION_LEVELS = [
        'high'   => ['task_ratio' => 0.10, 'reward_ratio' => 0.50],
        'medium' => ['task_ratio' => 0.30, 'reward_ratio' => 0.70],
        'low'    => ['task_ratio' => 0.60, 'reward_ratio' => 0.90],
        'clean'  => ['task_ratio' => 1.00, 'reward_ratio' => 1.00],
    ];

    public function __construct(
        private SocialTaskExecutionModel $model,
        private IPQualityService $ipService,
        private BrowserFingerprintService $fingerprintService,
        private SessionAnomalyService $sessionService,
        private TrustScoreService $trustService,
        private SocialTaskScoringService $scoringService,
        private AuditTrail $auditTrail,
        private NotificationService $notificationService,
        private SettingService $settingService,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * Risk Score ترکیبی
     */
    public function calculateRiskScore(int $userId, array $context = []): array
    {
        $cacheKey = "risk_score:{$userId}:" . md5(json_encode($context));
        
        $cached = cache()->get($cacheKey);
        if ($cached !== null) {
            return (array)$cached;
        }

        $result = $this->_calculateRiskScore($userId, $context);
        
        // Cache risk score calculation for 5 minutes to prevent IP check timing attacks (HIGH-NEW-03)
        cache()->put($cacheKey, $result, 5); // 5 minutes

        return $result;
    }

    private function _calculateRiskScore(int $userId, array $context = []): array
    {
        $ip = (string)($context['ip'] ?? '');
        $sessionId = (string)($context['session_id'] ?? '');
        $fingerprint = (string)($context['fingerprint'] ?? '');

        $components = [];
        $totalScore = 0.0;

        // MED-19: Upgrade hardcoded risk components weights to dynamically fetch from system configuration
        $weightIp      = (float)$this->settingService->get('risk_weight_ip', 0.35);
        $weightSession = (float)$this->settingService->get('risk_weight_session', 0.25);
        $weightMulti   = (float)$this->settingService->get('risk_weight_multi', 0.25);
        $weightPattern = (float)$this->settingService->get('risk_weight_pattern', 0.15);

        // ۱. IP Quality
        if ($ip !== '') {
            $ipResult = $this->ipService->check($ip);
            $ipScore = (int)($ipResult['score'] ?? 0);
            $components['ip'] = [
                'score' => $ipScore,
                'reasons' => $ipResult['reasons'] ?? [],
            ];
            $totalScore += $ipScore * $weightIp;
        }

        // ۲. Session Anomaly
        if ($sessionId !== '') {
            $sessionResult = $this->sessionService->analyze($userId, $sessionId);
            $sessionScore = (int)($sessionResult['score'] ?? 0);
            $components['session'] = [
                'score' => $sessionScore,
                'anomalies' => $sessionResult['anomalies'] ?? [],
            ];
            $totalScore += $sessionScore * $weightSession;
        }

        // ۳. Multi-Account Detection
        $multiResult = $this->detectMultiAccount($userId, $ip, $fingerprint);
        $components['multi_account'] = $multiResult;
        $totalScore += $multiResult['score'] * $weightMulti;

        // ۴. Pattern Anomaly
        $patternResult = $this->detectPatternAnomaly($userId);
        $components['pattern'] = $patternResult;
        $totalScore += $patternResult['score'] * $weightPattern;

        $finalScore = (int)min(100, $totalScore);

        return [
            'risk_score' => $finalScore,
            'components' => $components,
            'is_high_risk' => $finalScore > 50,
        ];
    }

    /**
     * تصمیم نهایی برای یک execution
     */
    public function decide(
        int   $userId,
        int   $executionId,
        float $taskScore,
        array $riskResult
    ): array {
        $trustScore = $this->trustService->get($userId);
        $riskScore  = (int)($riskResult['risk_score'] ?? 0);

        $minTaskScore  = (int)$this->settingService->get('antifraud_min_task_score', 70);
        $minTrustScore = (int)$this->settingService->get('antifraud_min_trust_score', 60);
        $maxRiskScore  = (int)$this->settingService->get('antifraud_max_risk_score', 30);
        $softMinScore  = (int)$this->settingService->get('antifraud_soft_min_score', 40);

        // MED-20: Disambiguated soft_approved branching logic by explicitly applying detailed operational tags
        if ($taskScore >= $minTaskScore && $trustScore >= $minTrustScore && $riskScore < $maxRiskScore) {
            $decision   = 'approved';
            $payReward  = true;
            $giveScore  = true;
            $flagReview = false;
            $reason     = 'score_trust_risk_all_good';
        } elseif ($taskScore >= $minTaskScore) {
            // Flow A: Verified tasks that suffer from external signals (low trust or device risk markers)
            $decision   = 'soft_approved';
            $payReward  = true;
            $giveScore  = true;
            $flagReview = false;
            $reason     = $trustScore < $minTrustScore ? 'soft_approved_low_trust' : 'soft_approved_high_risk';
        } elseif ($taskScore >= $softMinScore) {
            // Flow B: Bare minimum borderline quality tasks that skip direct trust bonuses
            $decision   = 'soft_approved';
            $payReward  = true;
            $giveScore  = false;
            $flagReview = $taskScore < 50;
            $reason     = 'soft_approved_borderline_score';
        } else {
            $decision   = 'rejected';
            $payReward  = false;
            $giveScore  = false;
            $flagReview = $taskScore < 20;
            $reason     = 'low_score_rejected';
        }

        if ($decision === 'approved') {
            $this->trustService->rewardGoodTask($userId, $executionId);
        } elseif ($decision === 'rejected') {
            $this->trustService->penalizeRejection($userId, $executionId);

            // ENHANCEMENT: Notify administrator automatically when highly critical rejections warrant verification flag
            if ($flagReview) {
                $this->notificationService->send([
                    'to' => 'admin',
                    'type' => 'antifraud.critical_rejection_flagged',
                    'payload' => [
                        'user_id'      => $userId,
                        'execution_id' => $executionId,
                        'task_score'   => $taskScore,
                        'risk_score'   => $riskScore,
                        'reason'       => $reason
                    ]
                ]);
            }
        }

        $this->auditTrail->record(
            $decision === 'approved' ? 'task.execution.approved' : 'task.execution.rejected',
            $userId,
            [
                'execution_id' => $executionId,
                'task_score'   => $taskScore,
                'trust_score'  => $trustScore,
                'risk_score'   => $riskScore,
                'decision'     => $decision,
                'reason'       => $reason,
            ]
        );

        return [
            'decision'    => $decision,
            'task_score'  => $taskScore,
            'trust_score' => $trustScore,
            'risk_score'  => $riskScore,
            'reason'      => $reason,
            'pay_reward'  => $payReward,
            'give_score'  => $giveScore,
            'flag_review' => $flagReview,
        ];
    }

    public function getRestrictionLevel(int $userId): array
    {
        $trustScore = $this->trustService->get($userId);

        // LOW-11: Scale static hardcoded boundaries into injectable dynamic thresholds
        $highLimit   = (int)$this->settingService->get('restriction_trust_limit_high', 20);
        $mediumLimit = (int)$this->settingService->get('restriction_trust_limit_medium', 40);
        $lowLimit    = (int)$this->settingService->get('restriction_trust_limit_low', 60);

        if ($trustScore < $highLimit) {
            $level = 'high';
        } elseif ($trustScore < $mediumLimit) {
            $level = 'medium';
        } elseif ($trustScore < $lowLimit) {
            $level = 'low';
        } else {
            $level = 'clean';
        }

        $levels = $this->settingService->get('antifraud_restriction_levels', self::DEFAULT_RESTRICTION_LEVELS);

        return array_merge(
            ['level' => $level, 'trust_score' => $trustScore],
            $levels[$level] ?? $levels['clean'] ?? self::DEFAULT_RESTRICTION_LEVELS['clean']
        );
    }

    public function filterTaskCount(int $userId, int $available): int
    {
        $restriction = $this->getRestrictionLevel($userId);
        return (int)ceil($available * $restriction['task_ratio']);
    }

    public function adjustedReward(int $userId, float $originalReward): float
    {
        $restriction = $this->getRestrictionLevel($userId);
        return round($originalReward * $restriction['reward_ratio'], 2);
    }

    private function detectMultiAccount(int $userId, string $ip, string $fingerprint): array
    {
        $score = 0;
        $reasons = [];

        if ($ip !== '') {
            $count = $this->model->getRecentExecutionsByIp($ip, $userId);

            // MED-21: Convert fixed IP repetition counts to configurable admin rules
            $highLimit = (int)$this->settingService->get('antifraud_ip_threshold_high', 5);
            $lowLimit  = (int)$this->settingService->get('antifraud_ip_threshold_low', 2);

            if ($count >= $highLimit) {
                $score += 60;
                $reasons[] = "IP مشترک با {$count} کاربر دیگر";
            } elseif ($count >= $lowLimit) {
                $score += 30;
                $reasons[] = "IP مشترک با {$count} کاربر دیگر";
            }
        }

        if ($fingerprint !== '') {
            $fpCount = $this->model->getSharedFingerprintUsers($fingerprint, $userId);
            if ($fpCount >= 1) {
                $score += 50;
                $reasons[] = "Device fingerprint با {$fpCount} حساب دیگر مشترک است";
            }
        }

        return ['score' => min(100, $score), 'reasons' => $reasons];
    }

    private function detectPatternAnomaly(int $userId): array
    {
        $score = 0;
        $reasons = [];

        $recent = $this->model->getRapidTaskStats($userId, 10);
        $cnt = (int)($recent->cnt ?? 0);
        $stddev = (float)($recent->stddev_time ?? 999);
        $avgTime = (float)($recent->avg_time ?? 0);

        if ($cnt >= 5) {
            $score += 40;
            $reasons[] = "{$cnt} تسک در ۱۰ دقیقه اخیر";

            if ($stddev < 2 && $avgTime > 0) {
                $score += 30;
                $reasons[] = 'زمان‌های انجام یکسان (الگوی Bot)';
            }
            $this->trustService->penalizeSuspicious($userId, 'rapid_task_pattern');
        }

        return ['score' => min(100, $score), 'reasons' => $reasons, 'details' => ['tasks_in_10min' => $cnt]];
    }

    /**
     * امتیازدهی به یک اجرا
     */
    public function scoreExecution(object $exec, array $payload): array
    {
        return $this->scoringService->calculate([
            'active_time' => (int)($payload['active_time'] ?? 0),
            'expected_time' => (int)($exec->expected_time ?? 60),
            'interactions' => (array)($payload['interactions'] ?? []),
            'behavior_signals' => (array)($payload['behavior_signals'] ?? []),
            'trust_modifier' => $this->trustService->getModifier((int)$exec->executor_id),
        ]);
    }

    public function decisionFromScore(array $score): array
    {
        // Simple mapping for now, can be expanded
        return [
            'decision' => $score['task_score'] >= 40 ? 'approve' : 'reject',
            'pay_reward' => $score['task_score'] >= 40,
        ];
    }
}

