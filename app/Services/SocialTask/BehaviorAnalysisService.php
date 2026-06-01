<?php

declare(strict_types=1);

namespace App\Services\SocialTask;


use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

/**
 * BehaviorAnalysisService
 *
 * تحلیل عمیق سیگنال‌های رفتاری موبایل و وب.
 */
class BehaviorAnalysisService
{
    private SocialTaskScoringService $scoring;
    private AppSettings $appSettings;
    public function __construct(
        SocialTaskScoringService $scoring,
        AppSettings $appSettings
    ) {        $this->scoring = $scoring;
        $this->appSettings = $appSettings;

            }

    /**
     * تحلیل کامل رفتار و برگرداندن گزارش + امتیاز
     */
    public function analyze(array $signals): array
    {
        $score = $this->scoring->calculateBehaviorScore($signals);
        $patterns = $this->detectPatterns($signals);
        $isBot = $this->isBotLike($signals, $patterns);
        $isFarm = $this->isFarmLike($signals);

        if ($isBot)  $score = max(0, $score - 30);
        if ($isFarm) $score = max(0, $score - 20);

        return [
            'behavior_score' => $score,
            'is_bot' => $isBot,
            'is_farm' => $isFarm,
            'patterns' => $patterns,
            'signals_summary' => $this->summarize($signals),
        ];
    }

    public function isBotLike(array $signals, array $patterns = []): bool
    {
        if (!empty($patterns)) {
            return in_array('bot_fixed_timing', $patterns, true)
                || in_array('bot_no_interaction', $patterns, true)
                || in_array('bot_straight_movement', $patterns, true);
        }

        $variance = (float)($signals['touch_timing_variance'] ?? 999);
        $tapCount = (int)($signals['tap_count'] ?? 0);
        $scrollCount = (int)($signals['scroll_count'] ?? 0);
        $avgDelay = (float)($signals['avg_action_delay_ms'] ?? 9999);

        if ($variance < 5 && $tapCount > 5) return true;
        if ($tapCount === 0 && $scrollCount === 0) return true;
        if ($avgDelay < 50 && $tapCount > 3) return true;

        return false;
    }

    public function isFarmLike(array $signals): bool
    {
        $sessionDuration = (int)($signals['session_duration'] ?? 0);
        $expectedTime = (int)($signals['expected_time'] ?? 0);
        $variance = (float)($signals['scroll_speed_variance'] ?? 999);
        $scrollCount = (int)($signals['scroll_count'] ?? 0);
        $blurCount = (int)($signals['app_blur_count'] ?? 0);

        // MED-10: Relax threshold from 1s to 3s to mitigate human false positives, adjustable from admin dashboard
        $maxTimeDiff = (int)$this->appSettings->get('behavior_farm_time_threshold', 3);

        if ($expectedTime > 0 && abs($sessionDuration - $expectedTime) <= $maxTimeDiff) return true;
        if ($scrollCount > 3 && $variance < 2) return true;
        if ($sessionDuration > 60 && $blurCount === 0 && $scrollCount === 0) return true;

        return false;
    }

    public function detectPatterns(array $signals): array
    {
        $patterns = [];

        $tapCount = (int)($signals['tap_count'] ?? 0);
        $scrollCount = (int)($signals['scroll_count'] ?? 0);
        $swipeCount = (int)($signals['swipe_count'] ?? 0);
        $variance = (float)($signals['touch_timing_variance'] ?? 999);
        $avgDelay = (float)($signals['avg_action_delay_ms'] ?? 0);
        $hesitation = (int)($signals['hesitation_count'] ?? 0);
        $blurCount = (int)($signals['app_blur_count'] ?? 0);
        $sessionDuration = (int)($signals['session_duration'] ?? 0);
        $activeTime = (int)($signals['active_time'] ?? 0);
        $expectedTime = (int)($signals['expected_time'] ?? 60);
        $scrollVariance = (float)($signals['scroll_speed_variance'] ?? 999);
        $cameraScore = (int)($signals['camera_score'] ?? -1);

        $maxTimeDiff = (int)$this->appSettings->get('behavior_farm_time_threshold', 3);

        if ($variance < 5 && $tapCount > 5) $patterns[] = 'bot_fixed_timing';
        if ($tapCount === 0 && $scrollCount === 0 && $swipeCount === 0) $patterns[] = 'bot_no_interaction';
        if ($avgDelay > 0 && $avgDelay < 80 && $tapCount > 3) $patterns[] = 'bot_straight_movement';
        if ($expectedTime > 0 && abs($sessionDuration - $expectedTime) <= $maxTimeDiff && $sessionDuration > 0) $patterns[] = 'farm_exact_timing';
        if ($scrollCount > 2 && $scrollVariance < 3) $patterns[] = 'farm_linear_scroll';
        if ($hesitation > 1 && $avgDelay > 300) $patterns[] = 'human_hesitation';

        $interactionTypes = ($tapCount > 0 ? 1 : 0) + ($scrollCount > 0 ? 1 : 0) + ($swipeCount > 0 ? 1 : 0);
        if ($interactionTypes >= 2) $patterns[] = 'human_mixed_interaction';
        if ($blurCount > 0 && $blurCount <= 3) $patterns[] = 'natural_app_switch';
        if ($sessionDuration > 0 && $activeTime > 0 && ($activeTime / $sessionDuration) < 0.3) $patterns[] = 'low_active_time';
        
        // MED-11: Exclude default -1 explicitly from failure classifications (indicates camera not active/used)
        if ($cameraScore === -1) {
            // Separate neutral state - camera verification was not active for this execution
        } elseif ($cameraScore >= 70) {
            $patterns[] = 'camera_verified';
        } elseif ($cameraScore >= 0 && $cameraScore < 50) {
            $patterns[] = 'camera_failed';
        }

        return $patterns;
    }

    public function needsCameraVerification(float $currentTaskScore, array $patterns): bool
    {
        if ($currentTaskScore < 50 && $currentTaskScore >= 25) {
            if (in_array('human_hesitation', $patterns, true) || in_array('human_mixed_interaction', $patterns, true)) {
                return false;
            }
            return true;
        }
        return false;
    }

    public function summarize(array $signals): array
    {
        return [
            'tap_count' => (int)($signals['tap_count'] ?? 0),
            'scroll_count' => (int)($signals['scroll_count'] ?? 0),
            'swipe_count' => (int)($signals['swipe_count'] ?? 0),
            'session_duration' => (int)($signals['session_duration'] ?? 0),
            'active_time' => (int)($signals['active_time'] ?? 0),
            'app_blur_count' => (int)($signals['app_blur_count'] ?? 0),
            'hesitation_count' => (int)($signals['hesitation_count'] ?? 0),
            'avg_action_delay' => round((float)($signals['avg_action_delay_ms'] ?? 0)),
            'touch_variance' => round((float)($signals['touch_timing_variance'] ?? 0), 2),
            'scroll_variance' => round((float)($signals['scroll_speed_variance'] ?? 0), 2),
            'camera_score' => (int)($signals['camera_score'] ?? -1),
        ];
    }
}

