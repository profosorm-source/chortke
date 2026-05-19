<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * SocialTaskExecutionModel - Manage Social Task Executions and Camera Verification Requests
 */
class SocialTaskExecutionModel extends Model
{
    protected static string $table = 'social_task_executions';

    public function getExecutionById(int $id, bool $forUpdate = false): ?object
    {
        $sql = "SELECT * FROM social_task_executions WHERE id = ?";
        if ($forUpdate) {
            if (!$this->db->inTransaction()) {
                throw new \RuntimeException('FOR UPDATE must be called inside an active transaction');
            }
            $sql .= " FOR UPDATE";
        }
        return $this->db->fetch($sql, [$id]);
    }

    public function getExecutionWithAd(int $executionId, int $userId, bool $forUpdate = false): ?object
    {
        $sql = "SELECT e.*, a.price_per_task, a.task_type, a.id AS ad_id, a.user_id, a.title AS ad_title
                FROM social_task_executions e
                INNER JOIN ads a ON a.id = e.ad_id
                WHERE e.id = ? AND e.executor_id = ?";
        if ($forUpdate) {
            if (!$this->db->inTransaction()) {
                throw new \RuntimeException('FOR UPDATE must be called inside an active transaction');
            }
            $sql .= " FOR UPDATE";
        }
        return $this->db->fetch($sql, [$executionId, $userId]);
    }

    public function getExecutionWithAdForAdvertiser(int $executionId, int $advertiserId, bool $forUpdate = false): ?object
    {
        $sql = "SELECT e.*, a.price_per_task, a.task_type, a.id AS ad_id, a.user_id, a.title AS ad_title
                FROM social_task_executions e
                INNER JOIN ads a ON a.id = e.ad_id
                WHERE e.id = ? AND a.user_id = ?";
        if ($forUpdate) {
            if (!$this->db->inTransaction()) {
                throw new \RuntimeException('FOR UPDATE must be called inside an active transaction');
            }
            $sql .= " FOR UPDATE";
        }
        return $this->db->fetch($sql, [$executionId, $advertiserId]);
    }

    public function getExecutionsByAd(int $adId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT ste.*, u.full_name AS executor_name
             FROM social_task_executions ste
             JOIN users u ON u.id = ste.executor_id
             WHERE ste.ad_id = ?
             ORDER BY ste.created_at DESC
             LIMIT ? OFFSET ?",
            [$adId, $limit, $offset]
        );
    }

    public function createExecution(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO social_task_executions
             (ad_id, executor_id, status, ip_address, user_agent, started_at, expected_time, created_at)
             VALUES (?, ?, 'pending', ?, ?, NOW(), ?, NOW())",
            [
                $data['ad_id'],
                $data['executor_id'],
                $data['ip_address'],
                $data['user_agent'],
                $data['expected_time']
            ]
        );
    }

    private const STATE_TRANSITIONS = [
        'pending' => ['started', 'cancelled'],
        'started' => ['submitted', 'expired', 'cancelled'],
        'submitted' => ['approved', 'soft_approved', 'rejected'],
        'approved' => [], // terminal
        'rejected' => [], // terminal
        'cancelled' => [], // terminal
        'expired' => [], // terminal
    ];

    private const ALLOWED_UPDATE_FIELDS = [
        'status', 'task_score', 'active_time', 'decision', 'rejection_reason', 
        'behavior_data', 'flag_review', 'flag_note', 'proof_url', 'proof_text', 
        'anti_fraud_score', 'reward_paid', 'reward_amount', 'override_reason', 
        'reviewed_by', 'reviewed_at', 'reject_reason'
    ];

    public function updateExecutionStatus(int $id, string $status, array $data = []): bool
    {
        // گرفتن وضعیت فعلی
        $current = $this->db->fetch("SELECT status FROM social_task_executions WHERE id = ? LIMIT 1", [$id]);
        
        if (!$current) {
            throw new \RuntimeException('Execution not found');
        }
        
        $currentStatus = $current->status;
        
        // بررسی معتبر بودن transition (تغییر وضعیت به خودش همیشه مجاز است)
        if ($currentStatus !== $status) {
            $allowed = self::STATE_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($status, $allowed, true)) {
                throw new \InvalidArgumentException(
                    "Invalid state transition: {$currentStatus} → {$status}"
                );
            }
        }

        $updates = ["status = ?", "updated_at = NOW()"];
        $params = [$status];

        foreach ($data as $key => $value) {
            if (!\in_array($key, self::ALLOWED_UPDATE_FIELDS, true)) {
                throw new \InvalidArgumentException("Invalid or restricted update column: " . $key);
            }
            $updates[] = "{$key} = ?";
            $params[] = $value;
        }

        $params[] = $id;
        return (bool)$this->db->query(
            "UPDATE social_task_executions SET " . implode(', ', $updates) . " WHERE id = ?",
            $params
        );
    }

    public function updateExecutionBehavior(int $id, string $behaviorData): bool
    {
        return (bool)$this->db->query(
            "UPDATE social_task_executions SET behavior_data = ?, updated_at = NOW() WHERE id = ?",
            [$behaviorData, $id]
        );
    }

    public function updateExecutionBehaviorJson(int $id, int $cameraScore, string $verifiedSignals): bool
    {
        return (bool)$this->db->query(
            "UPDATE social_task_executions
             SET behavior_data = JSON_SET(
                   COALESCE(behavior_data, '{}'),
                   '$.camera_score', ?,
                   '$.camera_signals', ?,
                   '$.camera_verified', 1
             )
             WHERE id = ?",
            [$cameraScore, $verifiedSignals, $id]
        );
    }

    public function getBehaviorData(int $id): ?string
    {
        $row = $this->db->fetch("SELECT behavior_data FROM social_task_executions WHERE id = ?", [$id]);
        return $row->behavior_data ?? null;
    }

    public function flagExecution(int $id, string $note): bool
    {
        return (bool)$this->db->query(
            "UPDATE social_task_executions SET flag_review = 1, flag_note = ?, updated_at = NOW() WHERE id = ?",
            [$note, $id]
        );
    }

    public function getRecentExecutionsByIp(string $ip, int $excludeUserId, int $hours = 24): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(DISTINCT executor_id) AS cnt
             FROM social_task_executions
             WHERE ip_address = ?
               AND executor_id != ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$ip, $excludeUserId, $hours]
        );
        return (int)($row->cnt ?? 0);
    }

    public function getSharedFingerprintUsers(string $fingerprint, int $excludeUserId): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(DISTINCT user_id) AS cnt
             FROM user_fingerprints
             WHERE fingerprint = ?
               AND user_id != ?",
            [$fingerprint, $excludeUserId]
        );
        return (int)($row->cnt ?? 0);
    }

    public function getRapidTaskStats(int $userId, int $minutes = 10): ?object
    {
        return $this->db->fetch(
            "SELECT COUNT(*) AS cnt,
                    AVG(active_time) AS avg_time,
                    STDDEV(active_time) AS stddev_time
             FROM social_task_executions
             WHERE executor_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$userId, $minutes]
        );
    }

    // --- Camera Request Operations ---

    public function getCameraRequest(int $executionId, array $statusList): ?object
    {
        $statusStr = "'" . implode("','", $statusList) . "'";
        return $this->db->fetch(
            "SELECT id, status FROM social_camera_requests
             WHERE execution_id = ? AND status IN ({$statusStr})
             LIMIT 1",
            [$executionId]
        );
    }

    public function createCameraRequest(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO social_camera_requests
               (execution_id, user_id, status, expires_at, created_at)
             VALUES (?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())",
            [$data['execution_id'], $data['user_id'], $data['expiry']]
        );
    }

    public function getPendingCameraRequest(int $executionId): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM social_camera_requests
             WHERE execution_id = ?
               AND status = 'pending'
               AND expires_at > NOW()
             LIMIT 1",
            [$executionId]
        );
    }

    public function getCameraRequestForUser(int $executionId, int $userId): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM social_camera_requests
             WHERE execution_id = ? AND user_id = ? AND status = 'pending'
             LIMIT 1",
            [$executionId, $userId]
        );
    }

    public function updateCameraRequestResult(int $id, int $score, string $signals): bool
    {
        return (bool)$this->db->query(
            "UPDATE social_camera_requests
             SET status = 'completed',
                 camera_score = ?,
                 verified_signals = ?,
                 completed_at = NOW()
             WHERE id = ?",
            [$score, $signals, $id]
        );
    }

    public function expireCameraRequests(int $executionId): bool
    {
        return (bool)$this->db->query(
            "UPDATE social_camera_requests
             SET status = 'expired'
             WHERE execution_id = ? AND status = 'pending' AND expires_at < NOW()",
            [$executionId]
        );
    }

    public function getCameraStats(): ?object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'completed') AS completed,
                SUM(status = 'expired')   AS expired,
                SUM(status = 'pending')   AS pending,
                AVG(CASE WHEN status = 'completed' THEN camera_score END) AS avg_score,
                SUM(CASE WHEN status = 'completed' AND camera_score >= 60 THEN 1 ELSE 0 END) AS passed
             FROM social_camera_requests"
        );
    }
}
