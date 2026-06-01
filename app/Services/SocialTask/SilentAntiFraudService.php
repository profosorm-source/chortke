<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\AuditTrail;
use App\Contracts\NotificationServiceInterface;
use App\Models\SocialTaskExecutionModel;
use App\Services\Settings\AppSettings;
use App\Services\Gamification\TrustService;
use App\Enums\ModuleContext;
use App\Services\AntiFraud\TaskExecutionEvaluatorService;
use App\Services\User\UserService;

use App\Contracts\LoggerInterface;
/**
 * SilentAntiFraudService
 *
 * تصمیم‌گیری نامحسوس (Silent Anti-Fraud)
 */
class SilentAntiFraudService
{
    private const DEFAULT_RESTRICTION_LEVELS = [
        'high'   => ['task_ratio' => 0.10, 'reward_ratio' => 0.50],
        'medium' => ['task_ratio' => 0.30, 'reward_ratio' => 0.70],
        'low'    => ['task_ratio' => 0.60, 'reward_ratio' => 0.90],
        'clean'  => ['task_ratio' => 1.00, 'reward_ratio' => 1.00],
    ];

    private SocialTaskExecutionModel $model;
    private IPQualityService $ipService;
    private SessionAnomalyService $sessionService;
    private TrustService $trustService;
    private UserService $userService;
    private AppSettings $appSettings;
    public function __construct(
        SocialTaskExecutionModel $model,
        IPQualityService $ipService,
        SessionAnomalyService $sessionService,
        TrustService $trustService,
        UserService $userService,
        AppSettings $appSettings
    ) {        $this->model = $model;
        $this->ipService = $ipService;
        $this->sessionService = $sessionService;
        $this->trustService = $trustService;
        $this->userService = $userService;
        $this->appSettings = $appSettings;
}

    /**
     * Risk Score ترکیبی
     */
    public function calculateRiskScore(int $userId, array $context = []): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\SocialTask\CalculateSilentRiskScoreJob::class);
        return $job->handle($userId, $context);
    }


    private function _calculateRiskScore(int $userId, array $context = []): array
    {
        $ip = (string)($context['ip'] ?? '');
        $sessionId = (string)($context['session_id'] ?? '');
        $fingerprint = (string)($context['fingerprint'] ?? '');

        $components = [];
        $totalScore = 0.0;

        // MED-19: Upgrade hardcoded risk components weights to dynamically fetch from system configuration
        $weightIp      = (float)$this->appSettings->get('risk_weight_ip', 0.35);
        $weightSession = (float)$this->appSettings->get('risk_weight_session', 0.25);
        $weightMulti   = (float)$this->appSettings->get('risk_weight_multi', 0.25);
        $weightPattern = (float)$this->appSettings->get('risk_weight_pattern', 0.15);

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
        $job = \Core\Container::getInstance()->make(\App\Jobs\SocialTask\DecideSilentAntiFraudJob::class);
        return $job->handle($userId, $executionId, $taskScore, $riskResult);
    }


    public function getRestrictionLevel(int $userId): array
    {
        $userObj = $this->userService->findById($userId);
        $trustScore = $userObj ? $this->trustService->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;

        // LOW-11: Scale static hardcoded boundaries into injectable dynamic thresholds
        $highLimit   = (int)$this->appSettings->get('restriction_trust_limit_high', 20);
        $mediumLimit = (int)$this->appSettings->get('restriction_trust_limit_medium', 40);
        $lowLimit    = (int)$this->appSettings->get('restriction_trust_limit_low', 60);

        if ($trustScore < $highLimit) {
            $level = 'high';
        } elseif ($trustScore < $mediumLimit) {
            $level = 'medium';
        } elseif ($trustScore < $lowLimit) {
            $level = 'low';
        } else {
            $level = 'clean';
        }

        $levels = $this->appSettings->get('antifraud_restriction_levels', self::DEFAULT_RESTRICTION_LEVELS);

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
            $highLimit = (int)$this->appSettings->get('antifraud_ip_threshold_high', 5);
            $lowLimit  = (int)$this->appSettings->get('antifraud_ip_threshold_low', 2);

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
            $userObj = $this->userService->findById($userId);
            if ($userObj) {
                $this->trustService->evaluate($userObj, ModuleContext::SOCIAL_TASKS, 'minor_violation', ['reason' => 'rapid_task_pattern']);
            }
        }

        return ['score' => min(100, $score), 'reasons' => $reasons, 'details' => ['tasks_in_10min' => $cnt]];
    }

    /**
     * امتیازدهی به یک اجرا
     */
    public function scoreExecution(object $exec, array $payload): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\SocialTask\ScoreSilentAntiFraudExecutionJob::class);
        return $job->handle($exec, $payload);
    }


    private function getTrustModifier(int $userId): float
    {
        $userObj = $this->userService->findById($userId);
        $trust = $userObj ? $this->trustService->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;

        $threshHigh = (float)$this->appSettings->get('trust_thresh_high', 80.0);
        $threshMed  = (float)$this->appSettings->get('trust_thresh_med', 60.0);
        $threshLow  = (float)$this->appSettings->get('trust_thresh_low', 40.0);
        $threshCrit = (float)$this->appSettings->get('trust_thresh_crit', 20.0);

        $modHigh     = (float)$this->appSettings->get('trust_mod_high', 10.0);
        $modMed      = (float)$this->appSettings->get('trust_mod_med', 5.0);
        $modLow      = (float)$this->appSettings->get('trust_mod_low', 0.0);
        $modCrit     = (float)$this->appSettings->get('trust_mod_crit', -5.0);
        $modVeryCrit = (float)$this->appSettings->get('trust_mod_verycrit', -10.0);

        if ($trust >= $threshHigh) return $modHigh;
        if ($trust >= $threshMed)  return $modMed;
        if ($trust >= $threshLow)  return $modLow;
        if ($trust >= $threshCrit) return $modCrit;
        
        return $modVeryCrit;
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

