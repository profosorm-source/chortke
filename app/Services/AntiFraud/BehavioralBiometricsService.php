<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Contracts\LoggerInterface;
/**
 * BehavioralBiometricsService
 * 
 * تحلیل رفتار کاربر برای تشخیص Bot و Account Takeover
 */
class BehavioralBiometricsService
{
    private VelocityAndScoreModel $model;
private \Core\Cache $cache;
public function __construct(
        \Core\Cache $cache,
        VelocityAndScoreModel $model
    )
    {    $this->cache = $cache;

        
        $this->model = $model;
        }

    /**
     * تحلیل الگوی تایپ (Typing Pattern)
     */
    public function analyzeTypingPattern(int $userId, array $keystrokes): array
    {
        // Performance Guard: Check Redis cache first to avoid redundant heavy O(N) calculations
        $cacheKey = "biometrics:typing:{$userId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached && count($keystrokes) <= ($cached['keystroke_count'] ?? 0)) {
            return $cached;
        }

        if (count($keystrokes) > 250) {
            $keystrokes = \array_slice($keystrokes, 0, 250);
        }

        if (count($keystrokes) < 10) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل وجود ندارد',
                'keystroke_count' => count($keystrokes)
            ];
        }
        
        $intervals = [];
        $holdTimes = [];
        $downEvents = [];
        
        foreach ($keystrokes as $event) {
            // Privacy Guard (Issue 5): Do not use actual key character, use a hash for matching down/up pairs
            $keyId = md5((string)($event['key'] ?? 'unknown'));
            
            if ($event['type'] === 'down') {
                $downEvents[$keyId] = $event['timestamp'];
            } elseif ($event['type'] === 'up' && isset($downEvents[$keyId])) {
                $holdTime = $event['timestamp'] - $downEvents[$keyId];
                $holdTimes[] = $holdTime;
            }
        }
        
        $downTimestamps = array_values($downEvents);
        sort($downTimestamps);
        
        for ($i = 1; $i < count($downTimestamps); $i++) {
            $intervals[] = $downTimestamps[$i] - $downTimestamps[$i - 1];
        }
        
        if (empty($intervals) || empty($holdTimes)) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل فاصله‌ها وجود ندارد'
            ];
        }
        
        $avgInterval = array_sum($intervals) / count($intervals);
        $stddevInterval = $this->standardDeviation($intervals);
        
        $avgHoldTime = array_sum($holdTimes) / count($holdTimes);
        $stddevHoldTime = $this->standardDeviation($holdTimes);
        
        $suspiciousReasons = [];
        $riskScore = 0;
        
        if ($stddevInterval < 10 && count($intervals) > 20) {
            $suspiciousReasons[] = 'فاصله تایپ خیلی یکنواخت (احتمال Bot)';
            $riskScore += 40;
        }
        
        if ($avgInterval < 50) {
            $suspiciousReasons[] = 'سرعت تایپ غیرمعمول بالا';
            $riskScore += 35;
        }
        
        if ($stddevHoldTime < 5 && count($holdTimes) > 20) {
            $suspiciousReasons[] = 'زمان نگه‌داشتن کلیدها یکسان';
            $riskScore += 25;
        }
        
        $historicalPattern = $this->getUserTypingHistory($userId);
        if ($historicalPattern) {
            $deviation = abs($avgInterval - $historicalPattern['avg_interval']);
            if ($deviation > 100) {
                $suspiciousReasons[] = 'تغییر ناگهانی الگوی تایپ نسبت به سابقه';
                $riskScore += 30;
            }
        }
        
        $this->saveTypingPattern($userId, [
            'avg_interval' => $avgInterval,
            'stddev_interval' => $stddevInterval,
            'avg_hold_time' => $avgHoldTime,
            'keystroke_count' => count($keystrokes)
        ]);
        
        $result = [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'avg_interval_ms' => round($avgInterval, 2),
                'stddev_interval_ms' => round($stddevInterval, 2),
                'avg_hold_time_ms' => round($avgHoldTime, 2),
                'keystroke_count' => count($keystrokes)
            ]
        ];

        // Cache the result for 5 minutes to reduce CPU load (Issue 4)
        $this->cache->put($cacheKey, $result, 5);

        return $result;
    }

    /**
     * تحلیل الگوی حرکت موس (Mouse Movement Pattern)
     */
    public function analyzeMousePattern(int $userId, array $movements): array
    {
        // Performance Guard (Issue 4): Cache results to prevent O(N) overhead on repeated requests
        $cacheKey = "biometrics:mouse:{$userId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached && count($movements) <= ($cached['movement_count'] ?? 0)) {
            return $cached;
        }

        if (count($movements) > 250) {
            $movements = \array_slice($movements, 0, 250);
        }

        if (count($movements) < 20) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل موس وجود ندارد',
                'movement_count' => count($movements)
            ];
        }
        
        $distances = [];
        $angles = [];
        $speeds = [];
        $curvatures = [];
        
        for ($i = 1; $i < count($movements); $i++) {
            $prev = $movements[$i - 1];
            $curr = $movements[$i];
            
            $dx = $curr['x'] - $prev['x'];
            $dy = $curr['y'] - $prev['y'];
            $distance = sqrt($dx * $dx + $dy * $dy);
            $distances[] = $distance;
            
            $angle = atan2($dy, $dx);
            $angles[] = $angle;
            
            $timeDiff = ($curr['timestamp'] - $prev['timestamp']) / 1000;
            if ($timeDiff > 0) {
                $speed = $distance / $timeDiff;
                $speeds[] = $speed;
            }
            
            if ($i >= 2) {
                $prevAngle = $angles[$i - 2];
                $curvature = abs($angle - $prevAngle);
                $curvatures[] = $curvature;
            }
        }
        
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $avgCurvature = !empty($curvatures) ? array_sum($curvatures) / count($curvatures) : 0;
        if ($avgCurvature < 0.1 && count($movements) > 50) {
            $suspiciousReasons[] = 'حرکت موس خطی و غیرطبیعی';
            $riskScore += 45;
        }
        
        $stddevSpeed = $this->standardDeviation($speeds);
        if ($stddevSpeed < 10 && count($speeds) > 30) {
            $suspiciousReasons[] = 'سرعت موس یکنواخت';
            $riskScore += 35;
        }
        
        if (count($movements) < 50) {
            $suspiciousReasons[] = 'تعامل موس خیلی کم';
            $riskScore += 20;
        }
        
        $pauseCount = 0;
        for ($i = 1; $i < count($movements); $i++) {
            $timeDiff = ($movements[$i]['timestamp'] - $movements[$i - 1]['timestamp']) / 1000;
            if ($timeDiff > 0.5) {
                $pauseCount++;
            }
        }
        
        if ($pauseCount < 3 && count($movements) > 100) {
            $suspiciousReasons[] = 'عدم توقف طبیعی در حرکت موس';
            $riskScore += 30;
        }
        
        $result = [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'movement_count' => count($movements),
                'avg_curvature' => round($avgCurvature, 4),
                'avg_speed_px_s' => !empty($speeds) ? round(array_sum($speeds) / count($speeds), 2) : 0,
                'stddev_speed' => round($stddevSpeed, 2),
                'pause_count' => $pauseCount
            ]
        ];

        // Cache for 5 minutes
        $this->cache->put($cacheKey, $result, 5);

        return $result;
    }

    /**
     * تحلیل الگوی کلیک (Click Pattern)
     */
    public function analyzeClickPattern(array $clicks): array
    {
        // Performance Guard: Bound analysis count to block massive click payloads
        if (count($clicks) > 250) {
            $clicks = \array_slice($clicks, 0, 250);
        }

        if (count($clicks) < 5) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل کلیک وجود ندارد'
            ];
        }
        
        $intervals = [];
        for ($i = 1; $i < count($clicks); $i++) {
            $interval = $clicks[$i]['timestamp'] - $clicks[$i - 1]['timestamp'];
            $intervals[] = $interval;
        }
        
        $avgInterval = array_sum($intervals) / count($intervals);
        $stddevInterval = $this->standardDeviation($intervals);
        
        $suspiciousReasons = [];
        $riskScore = 0;
        
        if ($avgInterval < 200 && $stddevInterval < 20) {
            $suspiciousReasons[] = 'کلیک‌های خیلی سریع و یکنواخت (احتمال Auto-clicker)';
            $riskScore += 60;
        }
        
        $positions = array_map(fn($c) => $c['x'] . ',' . $c['y'], $clicks);
        $uniquePositions = array_unique($positions);
        
        if (count($uniquePositions) < count($clicks) * 0.3) {
            $suspiciousReasons[] = 'کلیک‌های تکراری در نقاط مشابه';
            $riskScore += 25;
        }
        
        return [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'click_count' => count($clicks),
                'avg_interval_ms' => round($avgInterval, 2),
                'stddev_interval_ms' => round($stddevInterval, 2),
                'unique_positions' => count($uniquePositions)
            ]
        ];
    }

    /**
     * تحلیل الگوی اسکرول (Scroll Behavior)
     */
    public function analyzeScrollBehavior(array $scrolls): array
    {
        // Performance Guard: Keep tracking within safe iterative range
        if (count($scrolls) > 250) {
            $scrolls = \array_slice($scrolls, 0, 250);
        }

        if (count($scrolls) < 5) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل اسکرول وجود ندارد'
            ];
        }
        
        $speeds = [];
        $directions = [];
        
        for ($i = 1; $i < count($scrolls); $i++) {
            $prev = $scrolls[$i - 1];
            $curr = $scrolls[$i];
            
            $distance = abs($curr['position'] - $prev['position']);
            $timeDiff = ($curr['timestamp'] - $prev['timestamp']) / 1000;
            
            if ($timeDiff > 0) {
                $speed = $distance / $timeDiff;
                $speeds[] = $speed;
            }
            
            $directions[] = $curr['direction'];
        }
        
        $avgSpeed = !empty($speeds) ? array_sum($speeds) / count($speeds) : 0;
        
        $suspiciousReasons = [];
        $riskScore = 0;
        
        if ($avgSpeed > 5000) {
            $suspiciousReasons[] = 'سرعت اسکرول غیرطبیعی بالا';
            $riskScore += 40;
        }
        
        $upScrolls = array_filter($directions, fn($d) => $d === 'up');
        if (count($upScrolls) === 0 && count($scrolls) > 20) {
            $suspiciousReasons[] = 'عدم اسکرول به سمت بالا (رفتار غیرطبیعی)';
            $riskScore += 25;
        }
        
        return [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'scroll_count' => count($scrolls),
                'avg_speed_px_s' => round($avgSpeed, 2),
                'up_scroll_ratio' => round(count($upScrolls) / (count($scrolls) ?: 1), 2)
            ]
        ];
    }

    /**
     * تحلیل الگوی تعامل با فرم (Form Interaction)
     */
    public function analyzeFormInteraction(array $formData): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $fillTime = $formData['submit_time'] - $formData['form_load_time'];
        
        if ($fillTime < 2000 && count($formData['fields'] ?? []) >= 3) {
            $suspiciousReasons[] = 'پر کردن فرم خیلی سریع (احتمال Auto-fill)';
            $riskScore += 50;
        }
        
        $focusEvents = $formData['focus_count'] ?? 0;
        if ($focusEvents < count($formData['fields'] ?? []) * 0.5) {
            $suspiciousReasons[] = 'تعداد focus event کمتر از حد انتظار';
            $riskScore += 30;
        }
        
        if (($formData['field_changes'] ?? 0) === 0) {
            $suspiciousReasons[] = 'عدم ویرایش یا تغییر فیلدها';
            $riskScore += 40;
        }
        
        return [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'fill_time_ms' => $fillTime,
                'focus_count' => $focusEvents,
                'field_count' => count($formData['fields'] ?? [])
            ]
        ];
    }

    /**
     * تحلیل جامع رفتاری
     */
    public function comprehensiveAnalysis(int $userId, array $behaviorData): array
    {
        $results = [];
        
        if (isset($behaviorData['keystrokes'])) {
            $results['typing'] = $this->analyzeTypingPattern($userId, $behaviorData['keystrokes']);
        }
        
        if (isset($behaviorData['mouse_movements'])) {
            $results['mouse'] = $this->analyzeMousePattern($userId, $behaviorData['mouse_movements']);
        }
        
        if (isset($behaviorData['clicks'])) {
            $results['clicks'] = $this->analyzeClickPattern($behaviorData['clicks']);
        }
        
        if (isset($behaviorData['scrolls'])) {
            $results['scroll'] = $this->analyzeScrollBehavior($behaviorData['scrolls']);
        }
        
        if (isset($behaviorData['form'])) {
            $results['form'] = $this->analyzeFormInteraction($behaviorData['form']);
        }
        
        $totalRisk = 0;
        $count = 0;
        
        foreach ($results as $analysis) {
            if (isset($analysis['risk_score'])) {
                $totalRisk += $analysis['risk_score'];
                $count++;
            }
        }
        
        $avgRisk = $count > 0 ? $totalRisk / $count : 0;
        
        return [
            'overall_risk_score' => round($avgRisk, 2),
            'is_bot_likely' => $avgRisk >= 60,
            'analyses' => $results
        ];
    }

    private function standardDeviation(array $values): float
    {
        if (empty($values)) {
            return 0;
        }
        
        $mean = array_sum($values) / count($values);
        $variance = 0;
        
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        $variance /= count($values);
        
        return sqrt($variance);
    }

    private function getUserTypingHistory(int $userId): ?array
    {
        $result = $this->model->getLastTypingPattern($userId);
        
        if (!$result) {
            return null;
        }
        
        return [
            'avg_interval' => (float)$result->avg_interval,
            'stddev_interval' => (float)$result->stddev_interval
        ];
    }

    private function saveTypingPattern(int $userId, array $pattern): void
    {
        $this->model->saveTypingPattern($userId, $pattern);
    }

    public function detectInputMethod(array $events): string
    {
        $hasTouchEvents = false;
        $hasMouseEvents = false;
        
        foreach ($events as $event) {
            if (isset($event['type'])) {
                if (in_array($event['type'], ['touchstart', 'touchmove', 'touchend'])) {
                    $hasTouchEvents = true;
                }
                if (in_array($event['type'], ['mousedown', 'mousemove', 'mouseup'])) {
                    $hasMouseEvents = true;
                }
            }
        }
        
        if ($hasTouchEvents && !$hasMouseEvents) {
            return 'touch';
        } elseif ($hasMouseEvents && !$hasTouchEvents) {
            return 'mouse';
        } elseif ($hasTouchEvents && $hasMouseEvents) {
            return 'hybrid';
        }
        
        return 'unknown';
    }
}


