<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;


use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

/**
 * TaskExecutionEvaluatorService
 *
 * ارزیابی کیفیت و ریسک رفتار کاربر در زمان اجرای تسک (AntiFraud).
 */
class TaskExecutionEvaluatorService
{
    private AppSettings $appSettings;
    public function __construct(
        AppSettings $appSettings
    ) {        $this->appSettings = $appSettings;

            }

    /**
     * محاسبه Task Score کامل
     */
    public function calculate(array $data): array
    {
        $timeScore = $this->calculateTimeScore(
            (int)($data['active_time'] ?? 0),
            (int)($data['expected_time'] ?? 60)
        );
        $interactionScore = $this->calculateInteractionScore(
            (array)($data['interactions'] ?? [])
        );
        $behaviorScore = $this->calculateBehaviorScore(
            (array)($data['behavior_signals'] ?? [])
        );
        $trustModifier = $this->clamp((float)($data['trust_modifier'] ?? 0), -10, 10);

        $penalties = $this->calculatePenalties($data, $interactionScore);
        $penaltySum = array_sum(array_column($penalties, 'value'));

        $cameraBonus = 0;
        $signals = (array)($data['behavior_signals'] ?? []);
        if (!empty($signals['camera_verified'])) {
            $cScore = (int)($signals['camera_score'] ?? 0);
            $cSignals = (array)($signals['camera_signals'] ?? []);
            $cameraBonus = $this->calculateCameraContribution($cScore, $cSignals);
        }

        // MED-13: Move hardcoded contribution weights to configurable admin settings
        $weightTime = (float)$this->appSettings->get('scoring_weight_time', 0.30);
        $weightInteraction = (float)$this->appSettings->get('scoring_weight_interaction', 0.25);
        $weightBehavior = (float)$this->appSettings->get('scoring_weight_behavior', 0.20);

        $rawScore = ($timeScore * $weightTime)
            + ($interactionScore * $weightInteraction)
            + ($behaviorScore * $weightBehavior)
            + $trustModifier
            + $cameraBonus
            + $penaltySum;

        $taskScore = $this->clamp($rawScore, 0, 100);

        return [
            'task_score'        => round($taskScore, 1),
            'time_score'        => $timeScore,
            'interaction_score' => $interactionScore,
            'behavior_score'    => $behaviorScore,
            'trust_modifier'    => $trustModifier,
            'penalties'         => $penalties,
            'camera_bonus'      => $cameraBonus,
            'breakdown'         => [
                'time_contribution'        => round($timeScore * $weightTime, 1),
                'interaction_contribution' => round($interactionScore * $weightInteraction, 1),
                'behavior_contribution'    => round($behaviorScore * $weightBehavior, 1),
                'camera_contribution'      => $cameraBonus,
            ],
        ];
    }

    public function calculateTimeScore(int $activeTime, int $expectedTime): int
    {
        if ($expectedTime <= 0) return 0;
        $ratio = $activeTime / $expectedTime;

        // MED-12: Replace unfair discrete steps with fair, linear interpolation maps
        if ($ratio >= 1.0)  return 100;
        if ($ratio <= 0.10) return 10;

        // Maps ratios 0.10 to 1.00 uniformly to score range 10 to 100
        $score = 10 + (($ratio - 0.10) / 0.90) * 90;
        return (int)round($score);
    }

    public function calculateInteractionScore(array $interactions): int
    {
        $types = array_unique($interactions);
        $count = count($types);
        $hasScroll = in_array('scroll', $types, true);
        $hasClick  = in_array('click', $types, true);
        $hasTap    = in_array('tap', $types, true);

        if ($hasScroll && $hasClick && $hasTap) return 25;
        if ($count >= 2) return 20;
        if ($count === 1) return 10;
        return 0;
    }

    public function calculateBehaviorScore(array $signals): int
    {
        $touch   = $this->scoreTouchBehavior($signals);
        $scroll  = $this->scoreScrollBehavior($signals);
        $session = $this->scoreSessionIntegrity($signals);
        $focus   = $this->scoreFocusBehavior($signals);
        $micro   = $this->scoreMicroBehavior($signals);

        return min(100, $touch + $scroll + $session + $focus + $micro);
    }

    public function scoreTouchBehavior(array $s): int
    {
        $tapCount   = (int)($s['tap_count'] ?? 0);
        $swipeCount = (int)($s['swipe_count'] ?? 0);
        $pauseCount = (int)($s['touch_pauses'] ?? 0);
        $variance   = (float)($s['touch_timing_variance'] ?? 0);

        if ($variance < 5 && $tapCount > 5) return 0;
        if ($swipeCount === 0 && $pauseCount === 0) return 5;
        if ($swipeCount > 0 && $pauseCount === 0)  return 10;
        if ($swipeCount > 0 && $pauseCount > 0 && $variance < 50)  return 15;
        if ($swipeCount > 0 && $pauseCount > 2 && $variance >= 50) return 20;
        return 10;
    }

    public function scoreScrollBehavior(array $s): int
    {
        $scrollCount    = (int)($s['scroll_count'] ?? 0);
        $scrollVariance = (float)($s['scroll_speed_variance'] ?? 0);
        $scrollPauses   = (int)($s['scroll_pauses'] ?? 0);

        if ($scrollCount === 0) return 0;
        if ($scrollVariance < 5) return 5;
        if ($scrollVariance < 20 && $scrollPauses === 0) return 10;
        if ($scrollVariance >= 20 && $scrollPauses === 0) return 15;
        if ($scrollVariance >= 20 && $scrollPauses > 0) return 20;
        return 10;
    }

    public function scoreSessionIntegrity(array $s): int
    {
        $totalTime  = (int)($s['session_duration'] ?? 0);
        $activeTime = (int)($s['active_time'] ?? 0);
        $reconnects = (int)($s['reconnect_count'] ?? 0);

        if ($totalTime <= 0) return 0;
        $activeRatio = $activeTime / $totalTime;

        if ($reconnects > 3) return 5;
        if ($activeRatio < 0.40) return 10;
        if ($activeRatio < 0.70) return 15;
        if ($activeRatio >= 0.70) return 20;
        return 10;
    }

    public function scoreFocusBehavior(array $s): int
    {
        $outFocusCount   = (int)($s['app_blur_count'] ?? 0);
        $maxOutFocusSecs = (int)($s['max_blur_duration'] ?? 0);

        if ($outFocusCount === 0) return 20;
        if ($maxOutFocusSecs < 3 && $outFocusCount <= 2) return 15;
        if ($outFocusCount <= 4) return 10;
        if ($maxOutFocusSecs > 10) return 5;
        return 0;
    }

    public function scoreMicroBehavior(array $s): int
    {
        $hesitationCount = (int)($s['hesitation_count'] ?? 0);
        $avgActionDelay  = (float)($s['avg_action_delay_ms'] ?? 0);
        $naturalDelays   = (int)($s['natural_delay_count'] ?? 0);

        if ($avgActionDelay < 50 && $hesitationCount === 0) return 0;
        if ($avgActionDelay < 150) return 5;
        if ($avgActionDelay < 300 && $naturalDelays === 0) return 10;
        if ($avgActionDelay >= 300 && $hesitationCount > 0) return 15;
        if ($avgActionDelay >= 500 && $hesitationCount > 2) return 20;
        return 10;
    }

    public function calculatePenalties(array $data, int $interactionScore): array
    {
        $penalties = [];
        if ($interactionScore === 0) {
            $penalties[] = ['rule' => 'no_interaction', 'value' => -40, 'reason' => 'هیچ interaction ثبت نشد'];
        }
        $activeTime = (int)($data['active_time'] ?? 0);
        $expectedTime = (int)($data['expected_time'] ?? 60);
        if ($expectedTime > 0 && $activeTime < ($expectedTime * 0.15)) {
            $penalties[] = ['rule' => 'too_fast', 'value' => -30, 'reason' => 'زمان انجام خیلی کوتاه'];
        }
        $touchVariance = (float)($data['behavior_signals']['touch_timing_variance'] ?? 999);
        $tapCount = (int)($data['behavior_signals']['tap_count'] ?? 0);
        if ($touchVariance < 5 && $tapCount > 10) {
            $penalties[] = ['rule' => 'bot_pattern', 'value' => -20, 'reason' => 'الگوی حرکات رباتی'];
        }
        return $penalties;
    }

    public function riskModifier(int $riskScore): int
    {
        if ($riskScore < 20) return 5;
        if ($riskScore <= 50) return 0;
        return -10;
    }

    // MED-14: Converted to public to serve as the unified DRY scoring hook for camera evaluations
    public function calculateCameraContribution(int $cameraScore, array $verifiedSignals = []): int
    {
        $base = 0;
        if ($cameraScore >= 80) $base = 15;
        elseif ($cameraScore >= 60) $base = 8;
        elseif ($cameraScore >= 40) $base = 2;
        else $base = -10;

        $bonus = 0;
        $highValueSignals = ['follow_button_visible', 'username_match', 'subscribe_confirmed', 'like_button_active'];
        foreach ($highValueSignals as $sig) {
            if (in_array($sig, $verifiedSignals, true)) $bonus += 3;
        }

        return $base + min($bonus, 10);
    }

    private function clamp(float $val, float $min, float $max): float
    {
        return max($min, min($max, $val));
    }
}

