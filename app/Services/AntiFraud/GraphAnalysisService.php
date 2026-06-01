<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Contracts\LoggerInterface;
/**
 * GraphAnalysisService
 * 
 * سرویس تحلیل گراف برای تشخیص شبکه‌های تقلب
 */
class GraphAnalysisService
{
    private VelocityAndScoreModel $model;
    private const CLUSTER_MIN_SIZE = 3;
    private const CLUSTER_FRAUD_RATIO = 0.5;
    private const MAX_SHARED_IP_USERS = 5;
    private const CIRCULAR_TRANSACTION_THRESHOLD = 3;
    
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model
    )
    {        $this->logger = $logger;

                $this->model = $model;
    }
    
    /**
     * تحلیل شبکه یک کاربر
     */
    public function analyzeUserNetwork(int $userId, int $depth = 2): array
    {
        $this->logger->info('graph.analyze_started', [
            'user_id' => $userId,
            'depth' => $depth
        ]);
        
        $graph = $this->buildUserGraph($userId, $depth);
        
        $analysis = [
            'user_id' => $userId,
            'network_size' => count($graph['nodes']),
            'connection_count' => count($graph['edges']),
            'cluster_analysis' => $this->analyzeCluster($graph),
            'ip_sharing' => $this->analyzeIPSharing($userId),
            'circular_transactions' => $this->detectCircularTransactions($userId),
            'centrality' => $this->calculateCentrality($userId, $graph),
            'bot_network_risk' => $this->detectBotNetwork($graph),
        ];
        
        $analysis['overall_risk'] = $this->calculateNetworkRisk($analysis);
        
        $this->logger->info('graph.analyze_completed', [
            'user_id' => $userId,
            'risk_score' => $analysis['overall_risk']
        ]);
        
        return $analysis;
    }
    
    private function buildUserGraph(int $userId, int $maxDepth): array
    {
        $graph = [
            'nodes' => [],
            'edges' => [],
        ];
        
        $visited = [];
        $queue = [['id' => $userId, 'depth' => 0]];
        
        while (!empty($queue)) {
            $current = array_shift($queue);
            $currentId = $current['id'];
            $currentDepth = $current['depth'];
            
            if (isset($visited[$currentId]) || $currentDepth > $maxDepth) {
                continue;
            }
            
            $visited[$currentId] = true;
            
            $userInfo = $this->getUserInfo($currentId);
            if (!$userInfo) continue;
            
            $graph['nodes'][$currentId] = $userInfo;
            
            if ($currentDepth < $maxDepth) {
                $neighbors = $this->getNeighbors($currentId);
                
                foreach ($neighbors as $neighbor) {
                    $neighborId = (int)$neighbor['user_id'];
                    
                    $graph['edges'][] = [
                        'from' => $currentId,
                        'to' => $neighborId,
                        'type' => $neighbor['connection_type'],
                        'weight' => $neighbor['strength'],
                    ];
                    
                    if (!isset($visited[$neighborId])) {
                        $queue[] = [
                            'id' => $neighborId,
                            'depth' => $currentDepth + 1
                        ];
                    }
                }
            }
        }
        
        return $graph;
    }
    
    private function getUserInfo(int $userId): ?array
    {
        $user = $this->model->getUserInfo($userId);
        
        if (!$user) return null;
        
        return [
            'id' => (int)$user->id,
            'fraud_score' => (int)($user->fraud_score ?? 0),
            'is_blacklisted' => (bool)($user->is_blacklisted ?? false),
            'status' => $user->status,
            'age_days' => $this->calculateAccountAge($user->created_at),
        ];
    }
    
    private function getNeighbors(int $userId): array
    {
        $neighbors = [];
        
        $referralNeighbors = $this->model->getReferralConnections($userId);
        $neighbors = array_merge($neighbors, $referralNeighbors);
        
        $transactionNeighbors = $this->model->getTransactionConnections($userId);
        $neighbors = array_merge($neighbors, $transactionNeighbors);
        
        $ipNeighbors = $this->model->getIPConnections($userId);
        $neighbors = array_merge($neighbors, $ipNeighbors);
        
        $uniqueNeighbors = [];
        foreach ($neighbors as $neighbor) {
            $neighbor = (array)$neighbor;
            $key = (int)$neighbor['user_id'];
            if (!isset($uniqueNeighbors[$key])) {
                $uniqueNeighbors[$key] = $neighbor;
            } else {
                $uniqueNeighbors[$key]['strength'] += $neighbor['strength'];
            }
        }
        
        return array_values($uniqueNeighbors);
    }
    
    private function analyzeCluster(array $graph): array
    {
        $nodes = $graph['nodes'];
        
        if (count($nodes) < self::CLUSTER_MIN_SIZE) {
            return [
                'is_cluster' => false,
                'size' => count($nodes),
            ];
        }
        
        $suspiciousCount = 0;
        $blacklistedCount = 0;
        $totalFraudScore = 0;
        
        foreach ($nodes as $node) {
            if ($node['fraud_score'] > 70) {
                $suspiciousCount++;
            }
            
            if ($node['is_blacklisted']) {
                $blacklistedCount++;
            }
            
            $totalFraudScore += $node['fraud_score'];
        }
        
        $avgFraudScore = $totalFraudScore / count($nodes);
        $fraudRatio = $suspiciousCount / count($nodes);
        
        $isSuspiciousCluster = (
            $fraudRatio >= self::CLUSTER_FRAUD_RATIO ||
            $blacklistedCount >= 2 ||
            $avgFraudScore > 60
        );
        
        return [
            'is_cluster' => true,
            'is_suspicious' => $isSuspiciousCluster,
            'size' => count($nodes),
            'suspicious_count' => $suspiciousCount,
            'blacklisted_count' => $blacklistedCount,
            'avg_fraud_score' => round($avgFraudScore, 2),
            'fraud_ratio' => round($fraudRatio, 2),
        ];
    }
    
    private function analyzeIPSharing(int $userId): array
    {
        $sharedIPs = $this->model->getSharedIPs($userId);
        
        $suspicious = [];
        foreach ($sharedIPs as $ip) {
            if ($ip->user_count > self::MAX_SHARED_IP_USERS) {
                $suspicious[] = [
                    'ip' => $ip->ip_address,
                    'user_count' => (int)$ip->user_count,
                ];
            }
        }
        
        return [
            'shared_ip_count' => count($sharedIPs),
            'suspicious_ips' => $suspicious,
            'is_suspicious' => !empty($suspicious),
        ];
    }
    
    private function detectCircularTransactions(int $userId): array
    {
        $circularPaths = $this->model->getCircularPaths($userId, self::CIRCULAR_TRANSACTION_THRESHOLD);
        
        return [
            'detected' => !empty($circularPaths),
            'count' => count($circularPaths),
            'paths' => array_slice($circularPaths, 0, 5),
        ];
    }
    
    private function calculateCentrality(int $userId, array $graph): array
    {
        $edges = $graph['edges'];
        
        $degree = 0;
        foreach ($edges as $edge) {
            if ($edge['from'] == $userId || $edge['to'] == $userId) {
                $degree++;
            }
        }
        
        $weightedDegree = 0;
        foreach ($edges as $edge) {
            if ($edge['from'] == $userId || $edge['to'] == $userId) {
                $weightedDegree += $edge['weight'];
            }
        }
        
        return [
            'degree' => $degree,
            'weighted_degree' => $weightedDegree,
            'is_hub' => $degree > 10,
        ];
    }
    
    private function detectBotNetwork(array $graph): float
    {
        $nodes = $graph['nodes'];
        
        if (count($nodes) < 3) {
            return 0.0;
        }
        
        $botLikeCount = 0;
        
        foreach ($nodes as $node) {
            if ($node['age_days'] < 7 && $node['fraud_score'] > 50) {
                $botLikeCount++;
            }
        }
        
        $botRatio = $botLikeCount / count($nodes);
        return $botRatio > 0.5 ? $botRatio : 0.0;
    }
    
    private function calculateNetworkRisk(array $analysis): float
    {
        $riskScore = 0.0;
        
        if ($analysis['cluster_analysis']['is_suspicious'] ?? false) {
            $riskScore += 0.3;
        }
        
        if ($analysis['ip_sharing']['is_suspicious'] ?? false) {
            $riskScore += 0.2;
        }
        
        if ($analysis['circular_transactions']['detected'] ?? false) {
            $riskScore += 0.25;
        }
        
        if ($analysis['centrality']['is_hub'] ?? false) {
            $riskScore += 0.15;
        }
        
        $riskScore += ($analysis['bot_network_risk'] ?? 0) * 0.1;
        
        return min(1.0, (float)$riskScore);
    }
    
    /**
     * تشخیص شبکه‌های Sybil Attack
     */
    public function detectSybilNetwork(int $userId): array
    {
        $deviceSharing = $this->model->getDeviceSharing($userId);
        
        $isSybil = false;
        foreach ($deviceSharing as $device) {
            if ($device->account_count >= 3) {
                $isSybil = true;
                break;
            }
        }
        
        return [
            'is_sybil' => $isSybil,
            'shared_devices' => $deviceSharing,
        ];
    }
    
    private function calculateAccountAge(string $createdAt): int
    {
        $created = strtotime($createdAt);
        $now = time();
        return (int)(($now - $created) / 86400);
    }
}

