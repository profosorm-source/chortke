<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use Core\Cache;
use App\Contracts\LoggerInterface;
/**
 * VelocityCheckService
 * 
 * بررسی سرعت تراکنش‌ها (Velocity Checks)
 */
class VelocityCheckService
{
    private VelocityAndScoreModel $model;

    
    private const DEFAULT_RULES = [
        'deposit' => [
            '1h' => ['limit' => 5, 'period' => 3600],
            '24h' => ['limit' => 20, 'period' => 86400],
            '7d' => ['limit' => 50, 'period' => 604800],
        ],
        'withdrawal' => [
            '1h' => ['limit' => 3, 'period' => 3600],
            '24h' => ['limit' => 10, 'period' => 86400],
            '7d' => ['limit' => 30, 'period' => 604800],
        ],
        'transfer' => [
            '1h' => ['limit' => 10, 'period' => 3600],
            '24h' => ['limit' => 50, 'period' => 86400],
            '7d' => ['limit' => 200, 'period' => 604800],
        ],
        'social_task' => [
            '1h' => ['limit' => 20, 'period' => 3600],
            '24h' => ['limit' => 100, 'period' => 86400],
            '7d' => ['limit' => 500, 'period' => 604800],
        ],
        'login' => [
            '5m' => ['limit' => 5, 'period' => 300],
            '1h' => ['limit' => 10, 'period' => 3600],
            '24h' => ['limit' => 30, 'period' => 86400],
        ],
        'password_change' => [
            '1h' => ['limit' => 2, 'period' => 3600],
            '24h' => ['limit' => 5, 'period' => 86400],
            '7d' => ['limit' => 10, 'period' => 604800],
        ],
    ];
    
    private const AMOUNT_LIMITS = [
        'deposit' => [
            '1h' => 50000000,
            '24h' => 200000000,
            '7d' => 1000000000,
        ],
        'withdrawal' => [
            '1h' => 20000000,
            '24h' => 100000000,
            '7d' => 500000000,
        ],
    ];
    
    private \App\Services\DistributedLockService $lockService;
    private array $activeLocks = [];

    private \Core\Cache $cache;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model,
        \App\Services\DistributedLockService $lockService
    ) {        $this->cache = $cache;
        $this->logger = $logger;

        
        $this->model = $model;
        $this->lockService = $lockService;

        // Register shutdown function to release any unreleased locks gracefully
        register_shutdown_function([$this, 'releaseAllLocks']);
    }
    
    /**
     * بررسی سرعت برای یک عملیات
     */
    public function check(int $userId, string $actionType, array $context = []): array
    {
        $this->logger->info('velocity.check_started', [
            'user_id' => $userId,
            'action_type' => $actionType
        ]);

        // 🔒 Enforce distributed locking when updating/counting velocity to prevent transaction race conditions
        $lockKey = "velocity_check:{$userId}:{$actionType}";
        $lock = $this->lockService->acquire($lockKey, 10, 5); // 10s TTL, 5s max wait
        if (!$lock['acquired']) {
            $this->logger->warning('velocity.lock_failed', ['user_id' => $userId, 'action_type' => $actionType]);
            return [
                'allowed' => false,
                'reason' => 'سیستم در حال حاضر مشغول است. لطفاً چند لحظه دیگر تلاش کنید.'
            ];
        }

        // Store the lock token
        $this->activeLocks[$lockKey] = $lock['token'];
        
        $countCheck = $this->checkCountVelocity($userId, $actionType);
        if (!$countCheck['allowed']) {
            $this->releaseLock($lockKey);
            return $countCheck;
        }
        
        if (isset($context['amount']) && $this->hasAmountLimit($actionType)) {
            $amountCheck = $this->checkAmountVelocity(
                $userId, 
                $actionType, 
                (float)$context['amount']
            );
            
            if (!$amountCheck['allowed']) {
                $this->releaseLock($lockKey);
                return $amountCheck;
            }
        }
        
        $patternCheck = $this->checkPatternVelocity($userId, $actionType, $context);
        if (!$patternCheck['allowed']) {
            $this->releaseLock($lockKey);
            return $patternCheck;
        }
        
        return [
            'allowed' => true,
            'reason' => null,
            'remaining' => $this->getRemainingCount($userId, $actionType),
        ];
    }
    
    private function checkCountVelocity(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        
        if (empty($rules)) {
            return ['allowed' => true];
        }
        
        foreach ($rules as $period => $config) {
            $limit = $config['limit'];
            $seconds = $config['period'];
            
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $count = $this->cache->get($cacheKey);
            
            if ($count === null) {
                $count = $this->model->getTransactionCount($userId, $actionType, $seconds);
                $this->cache->set($cacheKey, $count, $seconds);
            }
            
            if ($count >= $limit) {
                $this->logger->warning('velocity.limit_exceeded', [
                    'user_id' => $userId,
                    'action_type' => $actionType,
                    'period' => $period,
                    'count' => $count,
                    'limit' => $limit
                ]);
                
                return [
                    'allowed' => false,
                    'reason' => "محدودیت تعداد در {$period} رسیده است",
                    'limit' => $limit,
                    'current' => $count,
                    'period' => $period,
                    'reset_at' => time() + $seconds,
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    private function checkAmountVelocity(int $userId, string $actionType, float $amount): array
    {
        $limits = $this->getAmountLimits($actionType);
        
        if (empty($limits)) {
            return ['allowed' => true];
        }
        
        foreach ($limits as $period => $maxAmount) {
            $seconds = $this->periodToSeconds($period);
            
            $currentTotal = $this->model->getTotalAmount($userId, $actionType, $seconds);
            $projectedTotal = $currentTotal + $amount;
            
            if ($projectedTotal > (float)$maxAmount) {
                $this->logger->warning('velocity.amount_limit_exceeded', [
                    'user_id' => $userId,
                    'action_type' => $actionType,
                    'period' => $period,
                    'current_total' => $currentTotal,
                    'requested_amount' => $amount,
                    'projected_total' => $projectedTotal,
                    'limit' => $maxAmount
                ]);
                
                return [
                    'allowed' => false,
                    'reason' => "محدودیت مبلغ در {$period} رسیده است",
                    'limit' => $maxAmount,
                    'current_total' => $currentTotal,
                    'requested_amount' => $amount,
                    'remaining_amount' => max(0, (float)$maxAmount - $currentTotal),
                    'period' => $period,
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    private function checkPatternVelocity(int $userId, string $actionType, array $context): array
    {
        if ($this->detectRepeatedTransactions($userId, $actionType, $context)) {
            return [
                'allowed' => false,
                'reason' => 'الگوی تراکنش‌های تکراری شناسایی شد',
                'pattern' => 'repeated_transactions'
            ];
        }
        
        if ($this->detectBurstPattern($userId, $actionType)) {
            return [
                'allowed' => false,
                'reason' => 'افزایش ناگهانی در تراکنش‌ها شناسایی شد',
                'pattern' => 'burst'
            ];
        }
        
        if (isset($context['amount']) && $this->detectRoundNumberPattern($userId, (float)$context['amount'])) {
            return [
                'allowed' => false,
                'reason' => 'الگوی مبالغ گرد مشکوک',
                'pattern' => 'round_numbers'
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function detectRepeatedTransactions(int $userId, string $actionType, array $context): bool
    {
        if (!isset($context['amount'])) {
            return false;
        }
        
        $count = $this->model->getRepeatedTransactionsCount($userId, $actionType, (float)$context['amount']);
        return $count >= 3;
    }
    
    private function detectBurstPattern(int $userId, string $actionType): bool
    {
        $recent = $this->model->getTransactionCount($userId, $actionType, 300);
        $historical = $this->model->getTransactionCount($userId, $actionType, 86400);
        
        $avgPer5Min = $historical / 288;
        
        if ($avgPer5Min > 0 && $recent > ($avgPer5Min * 5)) {
            $this->logger->warning('velocity.burst_detected', [
                'user_id' => $userId,
                'action_type' => $actionType,
                'recent_count' => $recent,
                'avg_per_5min' => $avgPer5Min
            ]);
            
            return true;
        }
        
        return false;
    }
    
    private function detectRoundNumberPattern(int $userId, float $amount): bool
    {
        $roundNumbers = [10000, 50000, 100000, 500000, 1000000, 5000000, 10000000];
        
        if (in_array((int)$amount, $roundNumbers)) {
            $stats = $this->model->getRoundNumberStats($userId);
            
            if ($stats['total'] >= 5) {
                $roundRatio = $stats['round_count'] / $stats['total'];
                
                if ($roundRatio > 0.8) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    public function record(int $userId, string $actionType, array $context = []): void
    {
        $rules = $this->getRules($actionType);
        
        foreach ($rules as $period => $config) {
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $current = $this->cache->get($cacheKey) ?? 0;
            $this->cache->set($cacheKey, $current + 1, $config['period']);
        }
        
        $this->logger->info('velocity.recorded', [
            'user_id' => $userId,
            'action_type' => $actionType,
            'context' => $context
        ]);

        // Release the lock immediately since record is complete
        $lockKey = "velocity_check:{$userId}:{$actionType}";
        $this->releaseLock($lockKey);
    }

    private function releaseLock(string $lockKey): void
    {
        if (isset($this->activeLocks[$lockKey])) {
            $token = $this->activeLocks[$lockKey];
            $this->lockService->release($lockKey, $token);
            unset($this->activeLocks[$lockKey]);
        }
    }

    public function releaseAllLocks(): void
    {
        foreach ($this->activeLocks as $lockKey => $token) {
            $this->lockService->release($lockKey, $token);
        }
        $this->activeLocks = [];
    }
    
    public function setCustomRules(string $actionType, array $rules): void
    {
        $cacheKey = "velocity:rules:{$actionType}";
        $this->cache->set($cacheKey, $rules, 86400);
    }
    
    public function reset(int $userId, string $actionType): void
    {
        $rules = $this->getRules($actionType);
        
        foreach ($rules as $period => $config) {
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $this->cache->delete($cacheKey);
        }
        
        $this->logger->info('velocity.reset', [
            'user_id' => $userId,
            'action_type' => $actionType
        ]);
    }
    
    public function getStatus(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        $status = [];
        
        foreach ($rules as $period => $config) {
            $count = $this->model->getTransactionCount($userId, $actionType, $config['period']);
            $limit = $config['limit'];
            
            $status[$period] = [
                'count' => $count,
                'limit' => $limit,
                'remaining' => max(0, $limit - $count),
                'percentage' => min(100, round(($count / $limit) * 100, 2)),
            ];
        }
        
        return $status;
    }
    
    private function getRules(string $actionType): array
    {
        $cacheKey = "velocity:rules:{$actionType}";
        $customRules = $this->cache->get($cacheKey);
        
        if ($customRules !== null) {
            return $customRules;
        }
        
        return self::DEFAULT_RULES[$actionType] ?? [];
    }
    
    private function getAmountLimits(string $actionType): array
    {
        return self::AMOUNT_LIMITS[$actionType] ?? [];
    }
    
    private function hasAmountLimit(string $actionType): bool
    {
        return isset(self::AMOUNT_LIMITS[$actionType]);
    }
    
    private function getRemainingCount(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        $remaining = [];
        
        foreach ($rules as $period => $config) {
            $count = $this->model->getTransactionCount($userId, $actionType, $config['period']);
            $remaining[$period] = max(0, $config['limit'] - $count);
        }
        
        return $remaining;
    }
    
    private function periodToSeconds(string $period): int
    {
        return match($period) {
            '5m' => 300,
            '1h' => 3600,
            '24h' => 86400,
            '7d' => 604800,
            default => 0
        };
    }
}

