<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * SocialTaskModel - Core Model for Social Ads, acting as a backward-compatible proxy wrapper for executions & analytics.
 */
class SocialTaskModel extends Model
{
    protected static string $table = 'social_ads';

    private SocialTaskExecutionModel $executionModel;
    private SocialTaskAnalyticsModel $analyticsModel;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $this->executionModel = new SocialTaskExecutionModel($db);
        $this->analyticsModel = new SocialTaskAnalyticsModel($db);
    }

    // --- Ads & Tasks (Core) ---

    public function getActiveAds(array $where, array $params, string $orderBy, int $limit): array
    {
        $whereStr = implode(' AND ', $where);
        return $this->db->fetchAll(
            "SELECT sa.*,
                    u.full_name AS advertiser_name,
                    COALESCE(ut.trust_score, 50) AS advertiser_trust
             FROM social_ads sa
             JOIN users u ON u.id = sa.advertiser_id
             LEFT JOIN social_user_trust ut ON ut.user_id = sa.advertiser_id
             WHERE {$whereStr}
             ORDER BY {$orderBy}
             LIMIT ?",
            [...$params, $limit]
        );
    }

    public function getAdById(int $adId, bool $forUpdate = false): ?object
    {
        $sql = "SELECT * FROM social_ads WHERE id = ?";
        if ($forUpdate) $sql .= " FOR UPDATE";
        return $this->db->fetch($sql, [$adId]);
    }

    public function updateAdStatus(int $adId, string $status, array $extraData = []): bool
    {
        $updates = ["status = ?", "updated_at = NOW()"];
        $params = [$status];

        foreach ($extraData as $key => $value) {
            $updates[] = "{$key} = ?";
            $params[] = $value;
        }

        $params[] = $adId;
        return (bool)$this->db->query(
            "UPDATE social_ads SET " . implode(', ', $updates) . " WHERE id = ?",
            $params
        );
    }

    public function decrementAdSlots(int $adId): int
    {
        $result = $this->db->query(
            "UPDATE social_ads SET remaining_slots = remaining_slots - 1 
             WHERE id = ? AND remaining_slots > 0",
            [$adId]
        );
        return $result instanceof \PDOStatement ? $result->rowCount() : 0;
    }

    public function incrementAdSlots(int $adId, int $count = 1): bool
    {
        return (bool)$this->db->query(
            "UPDATE social_ads SET remaining_slots = remaining_slots + ? WHERE id = ?",
            [$count, $adId]
        );
    }

    // --- Delegated Executions & Camera Methods ---

    public function getExecutionById(int $id, bool $forUpdate = false): ?object
    {
        return $this->executionModel->getExecutionById($id, $forUpdate);
    }

    public function getExecutionWithAd(int $executionId, int $userId, bool $forUpdate = false): ?object
    {
        return $this->executionModel->getExecutionWithAd($executionId, $userId, $forUpdate);
    }

    public function getExecutionWithAdForAdvertiser(int $executionId, int $advertiserId, bool $forUpdate = false): ?object
    {
        return $this->executionModel->getExecutionWithAdForAdvertiser($executionId, $advertiserId, $forUpdate);
    }

    public function createExecution(array $data): int
    {
        return $this->executionModel->createExecution($data);
    }

    public function updateExecutionStatus(int $id, string $status, array $data = []): bool
    {
        return $this->executionModel->updateExecutionStatus($id, $status, $data);
    }

    public function updateExecutionBehavior(int $id, string $behaviorData): bool
    {
        return $this->executionModel->updateExecutionBehavior($id, $behaviorData);
    }

    public function updateExecutionBehaviorJson(int $id, int $cameraScore, string $verifiedSignals): bool
    {
        return $this->executionModel->updateExecutionBehaviorJson($id, $cameraScore, $verifiedSignals);
    }

    public function getBehaviorData(int $id): ?string
    {
        return $this->executionModel->getBehaviorData($id);
    }

    public function flagExecution(int $id, string $note): bool
    {
        return $this->executionModel->flagExecution($id, $note);
    }

    public function getRecentExecutionsByIp(string $ip, int $excludeUserId, int $hours = 24): int
    {
        return $this->executionModel->getRecentExecutionsByIp($ip, $excludeUserId, $hours);
    }

    public function getSharedFingerprintUsers(string $fingerprint, int $excludeUserId): int
    {
        return $this->executionModel->getSharedFingerprintUsers($fingerprint, $excludeUserId);
    }

    public function getRapidTaskStats(int $userId, int $minutes = 10): ?object
    {
        return $this->executionModel->getRapidTaskStats($userId, $minutes);
    }

    public function getCameraRequest(int $executionId, array $statusList): ?object
    {
        return $this->executionModel->getCameraRequest($executionId, $statusList);
    }

    public function createCameraRequest(array $data): int
    {
        return $this->executionModel->createCameraRequest($data);
    }

    public function getPendingCameraRequest(int $executionId): ?object
    {
        return $this->executionModel->getPendingCameraRequest($executionId);
    }

    public function getCameraRequestForUser(int $executionId, int $userId): ?object
    {
        return $this->executionModel->getCameraRequestForUser($executionId, $userId);
    }

    public function updateCameraRequestResult(int $id, int $score, string $signals): bool
    {
        return $this->executionModel->updateCameraRequestResult($id, $score, $signals);
    }

    public function expireCameraRequests(int $executionId): bool
    {
        return $this->executionModel->expireCameraRequests($executionId);
    }

    public function getCameraStats(): ?object
    {
        return $this->executionModel->getCameraStats();
    }

    // --- Delegated Analytics & Trust Methods ---

    public function createRating(array $data): int
    {
        return $this->analyticsModel->createRating($data);
    }

    public function getAvgRating(int $userId, string $raterType): ?object
    {
        return $this->analyticsModel->getAvgRating($userId, $raterType);
    }

    public function getUserRatingHistory(int $userId, string $raterType, int $limit): array
    {
        return $this->analyticsModel->getUserRatingHistory($userId, $raterType, $limit);
    }

    public function getPendingRatings(int $limit, int $offset): array
    {
        return $this->analyticsModel->getPendingRatings($limit, $offset);
    }

    public function getRatingById(int $id): ?object
    {
        return $this->analyticsModel->getRatingById($id);
    }

    public function updateRatingStatus(int $id, string $status, int $adminId): bool
    {
        return $this->analyticsModel->updateRatingStatus($id, $status, $adminId);
    }

    public function getRatingStats(): ?object
    {
        return $this->analyticsModel->getRatingStats();
    }

    public function getRatingHistoryFull(int $userId, string $column, int $limit, int $offset): array
    {
        return $this->analyticsModel->getRatingHistoryFull($userId, $column, $limit, $offset);
    }

    public function hasUserRated(int $executionId, int $raterId, string $raterType): bool
    {
        return $this->analyticsModel->hasUserRated($executionId, $raterId, $raterType);
    }

    public function updateUserStats(int $userId, float $rating, int $count, string $type): bool
    {
        return $this->analyticsModel->updateUserStats($userId, $rating, $count, $type);
    }

    public function getUserTrust(int $userId, bool $forUpdate = false): ?object
    {
        return $this->analyticsModel->getUserTrust($userId, $forUpdate);
    }

    public function upsertUserTrust(int $userId, float $score): bool
    {
        return $this->analyticsModel->upsertUserTrust($userId, $score);
    }

    public function recordTrustAdjustment(array $data): bool
    {
        return $this->analyticsModel->recordTrustAdjustment($data);
    }

    public function saveTrustSnapshot(array $data): bool
    {
        return $this->analyticsModel->saveTrustSnapshot($data);
    }

    public function getExecutorStats(int $userId): ?object
    {
        return $this->analyticsModel->getExecutorStats($userId);
    }

    public function getAdvertiserAdStats(int $adId, int $advertiserId): ?object
    {
        return $this->analyticsModel->getAdvertiserAdStats($adId, $advertiserId);
    }

    public function getWeeklyExecutionStats(int $userId): ?object
    {
        return $this->analyticsModel->getWeeklyExecutionStats($userId);
    }

    public function getRecentActiveExecutors(int $days = 7): array
    {
        return $this->analyticsModel->getRecentActiveExecutors($days);
    }

    public function getExecutorHistory(int $userId, int $limit, int $offset): array
    {
        return $this->analyticsModel->getExecutorHistory($userId, $limit, $offset);
    }

    public function getMedianReward(): float
    {
        return $this->analyticsModel->getMedianReward();
    }

    // --- Transactions ---

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollBack(): void
    {
        $this->db->rollBack();
    }
}
