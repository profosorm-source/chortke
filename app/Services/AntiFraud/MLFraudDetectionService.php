<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

// M-01: Removed AntiFraudModel dependency - uses specific models via composition
use App\Contracts\LoggerInterface;
use App\Models\VelocityAndScoreModel;
/**
 * MLFraudDetectionService
 * 
 * سرویس تشخیص تقلب بر اساس Machine Learning
 */
class MLFraudDetectionService
{
    private VelocityAndScoreModel $model;
    private const RISK_THRESHOLD_HIGH = 0.75;
    private const RISK_THRESHOLD_MEDIUM = 0.50;
    private const RISK_THRESHOLD_LOW = 0.25;
    
    private const WEIGHTS = [
        'transaction_velocity' => 0.25,
        'amount_anomaly' => 0.20,
        'time_pattern' => 0.15,
        'device_diversity' => 0.15,
        'behavior_change' => 0.15,
        'network_risk' => 0.10,
    ];
    
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model
    )
    {        $this->logger = $logger;

                $this->model = $model;
    }
    
    /**
     * تحلیل اصلی تقلب برای یک کاربر
     */
    public function analyzeUser(int $userId, array $context = []): array
    {
        $this->logger->info('ml_fraud.analysis_started', [
            'user_id' => $userId,
            'context' => $context
        ]);
        
        $features = $this->extractFeatures($userId, $context);
        $riskScore = $this->calculateRiskScore($features);
        $riskLevel = $this->determineRiskLevel($riskScore);
        $suspiciousFactors = $this->identifySuspiciousFactors($features);
        
        $this->storePrediction($userId, $riskScore, $features);
        
        $result = [
            'risk_score' => round($riskScore, 4),
            'risk_level' => $riskLevel,
            'factors' => $suspiciousFactors,
            'recommendation' => $this->getRecommendation($riskLevel),
            'features' => $features,
        ];
        
        $this->logger->info('ml_fraud.analysis_completed', [
            'user_id' => $userId,
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel
        ]);
        
        return $result;
    }
    
    private function extractFeatures(int $userId, array $context): array
    {
        $features = [];
        
        $features['transaction_velocity'] = $this->calculateTransactionVelocity($userId);
        $features['amount_anomaly'] = $this->detectAmountAnomaly($userId, (float)($context['transaction_amount'] ?? 0));
        $features['time_pattern'] = $this->analyzeTimePattern($userId);
        $features['device_diversity'] = $this->analyzeDeviceDiversity($userId);
        $features['behavior_change'] = $this->detectBehaviorChange($userId);
        $features['network_risk'] = $this->analyzeNetworkRisk($userId);
        
        return $features;
    }
    
    private function calculateTransactionVelocity(int $userId): float
    {
        $velocityScore = 0.0;
        
        $txn1h = $this->model->getRecentTransactionCount($userId, 1);
        $txn24h = $this->model->getRecentTransactionCount($userId, 24);
        $txn7d = $this->model->getRecentTransactionCount($userId, 168);
        
        $avgDaily = $this->model->getUserAverageDaily($userId);
        
        if ($txn1h > 10) {
            $velocityScore += 0.5;
        } elseif ($txn1h > 5) {
            $velocityScore += 0.3;
        }
        
        if ($avgDaily > 0 && $txn24h > ($avgDaily * 3)) {
            $velocityScore += 0.3;
        }
        
        if ($avgDaily > 0 && ($txn7d / 7) > ($avgDaily * 2)) {
            $velocityScore += 0.2;
        }
        
        return min(1.0, $velocityScore);
    }
    
    private function detectAmountAnomaly(int $userId, float $currentAmount): float
    {
        if ($currentAmount <= 0) {
            return 0.0;
        }
        
        $stats = $this->model->getTransactionAmountStats($userId);
        
        if ($stats['count'] < 5) {
            return 0.1;
        }
        
        $mean = $stats['mean'];
        $stdDev = $stats['std_dev'];
        
        if ($stdDev == 0) {
            return 0.0;
        }
        
        $zScore = abs(($currentAmount - $mean) / $stdDev);
        
        if ($zScore > 3.0) {
            return 0.9;
        } elseif ($zScore > 2.0) {
            return 0.6;
        } elseif ($zScore > 1.5) {
            return 0.3;
        }
        
        return 0.0;
    }
    
    private function analyzeTimePattern(int $userId): float
    {
        $hourlyActivity = $this->model->getHourlyActivity($userId);
        
        $suspicionScore = 0.0;
        $totalActivity = array_sum($hourlyActivity);
        
        if ($totalActivity == 0) {
            return 0.0;
        }
        
        $lateNightActivity = 0;
        for ($h = 2; $h <= 6; $h++) {
            $lateNightActivity += $hourlyActivity[$h] ?? 0;
        }
        
        $lateNightRatio = $lateNightActivity / $totalActivity;
        
        if ($lateNightRatio > 0.4) {
            $suspicionScore = 0.7;
        } elseif ($lateNightRatio > 0.2) {
            $suspicionScore = 0.4;
        }
        
        return $suspicionScore;
    }
    
    private function analyzeDeviceDiversity(int $userId): float
    {
        $deviceCount = $this->model->getDeviceCount($userId);
        
        if ($deviceCount > 5) {
            return 0.8;
        } elseif ($deviceCount > 3) {
            return 0.5;
        }
        
        return 0.0;
    }
    
    private function detectBehaviorChange(int $userId): float
    {
        $recentBehavior = $this->model->getBehaviorMetrics($userId, 7);
        $historicalBehavior = $this->model->getBehaviorMetrics($userId, 30, 7);
        
        if ($historicalBehavior['transaction_count'] < 10) {
            return 0.0;
        }
        
        $changeScore = 0.0;
        
        if ($historicalBehavior['avg_amount'] > 0) {
            $amountChange = abs(
                ($recentBehavior['avg_amount'] - $historicalBehavior['avg_amount']) 
                / $historicalBehavior['avg_amount']
            );
            
            if ($amountChange > 2.0) {
                $changeScore += 0.4;
            } elseif ($amountChange > 1.0) {
                $changeScore += 0.2;
            }
        }
        
        $recentFrequency = $recentBehavior['transaction_count'] / 7;
        $historicalFrequency = $historicalBehavior['transaction_count'] / 30;
        
        if ($historicalFrequency > 0) {
            $frequencyChange = abs(
                ($recentFrequency - $historicalFrequency) / $historicalFrequency
            );
            
            if ($frequencyChange > 3.0) {
                $changeScore += 0.4;
            } elseif ($frequencyChange > 1.5) {
                $changeScore += 0.2;
            }
        }
        
        return min(1.0, $changeScore);
    }
    
    private function analyzeNetworkRisk(int $userId): float
    {
        $userInfo = $this->model->getUserAndReferrerInfo($userId);
        
        $networkScore = 0.0;
        
        if ($userInfo && $userInfo->referred_by) {
            if ($userInfo->referrer_is_blacklisted) {
                $networkScore += 0.6;
            }
            
            if ($userInfo->referrer_fraud_score > 70) {
                $networkScore += 0.3;
            }
        }
        
        $sharedIPScore = $this->analyzeSharedIP($userId);
        $networkScore += $sharedIPScore * 0.4;
        
        return min(1.0, $networkScore);
    }
    
    private function analyzeSharedIP(int $userId): float
    {
        $ipData = $this->model->getSharedIPData($userId);
        
        $suspicionScore = 0.0;
        
        foreach ($ipData as $ip) {
            if ($ip->user_count > 5) {
                $suspicionScore += 0.4;
            }
            
            if ($ip->suspicious_users > 0) {
                $suspicionScore += 0.5;
            }
        }
        
        return min(1.0, $suspicionScore);
    }
    
    private function calculateRiskScore(array $features): float
    {
        $totalScore = 0.0;
        
        foreach (self::WEIGHTS as $feature => $weight) {
            $totalScore += ($features[$feature] ?? 0.0) * $weight;
        }
        
        return min(1.0, max(0.0, $totalScore));
    }
    
    private function determineRiskLevel(float $score): string
    {
        if ($score >= self::RISK_THRESHOLD_HIGH) {
            return 'high';
        } elseif ($score >= self::RISK_THRESHOLD_MEDIUM) {
            return 'medium';
        } elseif ($score >= self::RISK_THRESHOLD_LOW) {
            return 'low';
        }
        
        return 'safe';
    }
    
    private function identifySuspiciousFactors(array $features): array
    {
        $suspicious = [];
        
        foreach ($features as $feature => $score) {
            if ($score > 0.5) {
                $suspicious[] = [
                    'factor' => $feature,
                    'score' => round($score, 2),
                    'severity' => $score > 0.75 ? 'high' : 'medium'
                ];
            }
        }
        
        return $suspicious;
    }
    
    private function getRecommendation(string $riskLevel): string
    {
        return match($riskLevel) {
            'high' => 'block_transaction',
            'medium' => 'manual_review',
            'low' => 'monitor',
            default => 'allow'
        };
    }
    
    private function storePrediction(int $userId, float $riskScore, array $features): void
    {
        try {
            $this->model->storePrediction($userId, $riskScore, $features);
        } catch (\Exception $e) {
            $this->logger->warning('ml_fraud.store_prediction_failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * به‌روزرسانی مدل با feedback (برای یادگیری)
     */
    public function provideFeedback(int $userId, string $actualOutcome): void
    {
        $this->model->updatePredictionFeedback($userId, $actualOutcome);
        
        $this->logger->info('ml_fraud.feedback_received', [
            'user_id' => $userId,
            'outcome' => $actualOutcome
        ]);
    }
}

