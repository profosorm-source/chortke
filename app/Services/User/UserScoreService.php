<?php

declare(strict_types=1);

namespace App\Services\User;

use Core\Database;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;

class UserScoreService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        private RiskPolicyService $policyService,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function applyEventDelta(int $userId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        // Insert or update score in database
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_scores (user_id, domain, score, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE score = score + VALUES(score), updated_at = NOW()
            ");
            return $stmt->execute([$userId, $domain, $delta]);
        } catch (\Throwable $e) {
            $this->logger->error('user_score.apply_delta.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function getFraudScore(int $userId): float
    {
        try {
            $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = 'fraud' LIMIT 1");
            $stmt->execute([$userId]);
            $score = $stmt->fetchColumn();
            return $score ? (float)$score : 0.0;
        } catch (\Throwable $ignore) {
            return 0.0;
        }
    }

    public function getTaskScore(int $userId): float
    {
        try {
            $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = 'task' LIMIT 1");
            $stmt->execute([$userId]);
            $score = $stmt->fetchColumn();
            return $score ? (float)$score : 0.0;
        } catch (\Throwable $ignore) {
            return 0.0;
        }
    }

    public function getEffectiveScore(int $userId, string $domain, float $rawScore): float
    {
        return $rawScore;
    }

    public function incrementFraudRawScore(int $userId, float $delta, string $source, array $meta = []): bool
    {
        return $this->applyEventDelta($userId, 'fraud', $delta, $source, $meta);
    }

    public function createAdjustment(int $userId, string $domain, float $adjustment, string $reason, ?string $expiry = null, ?int $createdBy = null): array
    {
        return ['success' => true];
    }
}
