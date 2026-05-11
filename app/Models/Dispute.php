<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Dispute Model - مدل اشتراکی مدیریت اختلافات
 * 
 * این مدل برای تمامی ماژول‌ها (Task, Influencer, Order, etc) استفاده می‌شود.
 * جدول: disputes
 */
class Dispute extends Model
{
    // ┌─────────────────────────────────────────────────────────────┐
    // │ Status Constants
    // └─────────────────────────────────────────────────────────────┘
    public const STATUS_OPEN = 'open';
    public const STATUS_OPEN_PEER = 'open_peer';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_RESOLVED_PEER = 'resolved_peer';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_RESOLVED_ADMIN = 'resolved_admin';
    public const STATUS_RESOLVED_EXECUTOR = 'resolved_for_executor';
    public const STATUS_RESOLVED_ADVERTISER = 'resolved_for_advertiser';
    public const STATUS_CLOSED = 'closed';

    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_OPEN_PEER, self::STATUS_UNDER_REVIEW, self::STATUS_ESCALATED];
    public const CLOSED_STATUSES = [self::STATUS_RESOLVED_PEER, self::STATUS_RESOLVED_ADMIN, self::STATUS_RESOLVED_EXECUTOR, self::STATUS_RESOLVED_ADVERTISER, self::STATUS_CLOSED];
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT d.*, 
                   cu.full_name AS customer_name,
                   ou.full_name AS other_party_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function create(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO disputes (ref_type, ref_id, user_id, target_user_id, reason, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'open', NOW(), NOW())
        ");
        
        $ok = $stmt->execute([
            $data['ref_type'],
            $data['ref_id'],
            $data['user_id'],
            $data['target_user_id'] ?? null,
            $data['reason']
        ]);

        return $ok ? $this->find((int)$this->db->lastInsertId()) : null;
    }

    public function update(int $id, array $data): bool
    {
        $allowed = [
            'status', 'peer_deadline', 'resolution_note',
            'admin_decision', 'admin_id', 'admin_note',
            'penalty_amount', 'penalty_currency', 'penalty_target',
            'site_tax_amount', 'refund_percent', 'resolved_at', 'resolved_by'
        ];
        $fields = [];
        $values = [];
        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $values[] = $data[$f];
            }
        }
        if (empty($fields)) {
            return false;
        }
        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        $stmt = $this->db->prepare(
            "UPDATE disputes SET " . \implode(', ', $fields) . " WHERE id = ?"
        );
        return $stmt->execute($values);
    }

    public function addMessage(int $disputeId, int $userId, string $message, ?string $attachment = null, ?string $role = null): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO dispute_messages (dispute_id, user_id, role, message, attachment, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$disputeId, $userId, $role, $message, $attachment]);
    }

    public function getMessages(int $disputeId): array
    {
        $stmt = $this->db->prepare("
            SELECT m.*, u.full_name AS sender_name
            FROM dispute_messages m
            LEFT JOIN users u ON u.id = m.user_id
            WHERE m.dispute_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$disputeId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * Check if there's an open dispute for a task submission
     */
    public function hasOpenTaskDispute(int $submissionId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM disputes
            WHERE ref_type = 'task' AND ref_id = ? AND status IN ('open', 'under_review')
        ");
        $stmt->execute([$submissionId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Find dispute by ref_type and ref_id
     */
    public function findByRef(string $refType, int $refId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   cu.full_name AS customer_name,
                   ou.full_name AS other_party_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE d.ref_type = ? AND d.ref_id = ?
            ORDER BY d.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$refType, $refId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    // ──────────────────────────────────────
    // لیست ادمین
    // ──────────────────────────────────────

    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "d.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['ref_type'])) {
            $where[] = "d.ref_type = ?";
            $params[] = $filters['ref_type'];
        }
        if (!empty($filters['search'])) {
            // M31: Use addcslashes to escape wildcard characters
            $escaped = addcslashes($filters['search'], '%_\\');
            $s = '%' . $escaped . '%';
            $where[] = "(cu.full_name LIKE ? OR ou.full_name LIKE ?)";
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT d.*,
                   cu.full_name AS customer_name,
                   ou.full_name AS other_party_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE {$whereStr}
            ORDER BY d.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCount(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "d.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['ref_type'])) {
            $where[] = "d.ref_type = ?";
            $params[] = $filters['ref_type'];
        }
        if (!empty($filters['search'])) {
            // M31: Use addcslashes to escape wildcard characters
            $escaped = addcslashes($filters['search'], '%_\\');
            $s = '%' . $escaped . '%';
            $where[] = "(cu.full_name LIKE ? OR ou.full_name LIKE ?)";
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE {$whereStr}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ State Machine Validation
    // └─────────────────────────────────────────────────────────────┘

    private const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_UNDER_REVIEW, self::STATUS_RESOLVED_EXECUTOR, self::STATUS_RESOLVED_ADVERTISER, self::STATUS_CLOSED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_RESOLVED_EXECUTOR, self::STATUS_RESOLVED_ADVERTISER, self::STATUS_CLOSED],
        self::STATUS_OPEN_PEER => [self::STATUS_RESOLVED_PEER, self::STATUS_ESCALATED],
        self::STATUS_RESOLVED_PEER => [self::STATUS_CLOSED],
        self::STATUS_ESCALATED => [self::STATUS_RESOLVED_ADMIN],
        self::STATUS_RESOLVED_ADMIN => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
        self::STATUS_RESOLVED_EXECUTOR => [],
        self::STATUS_RESOLVED_ADVERTISER => [],
    ];

    public function canTransitionTo(string $currentStatus, string $targetStatus): bool
    {
        if (!isset(self::TRANSITIONS[$currentStatus])) {
            return false;
        }
        return \in_array($targetStatus, self::TRANSITIONS[$currentStatus], true);
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ Ownership & Validation Methods
    // └─────────────────────────────────────────────────────────────┘

    public function getSafe(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        return $this->find($id);
    }

    public function isParty(int $disputeId, int $userId): bool
    {
        $dispute = $this->getSafe($disputeId);
        if (!$dispute) {
            return false;
        }
        return $dispute->user_id === $userId || $dispute->target_user_id === $userId;
    }

    public function getUnreadMessageCount(int $disputeId, int $userId): int
    {
        $result = $this->db->prepare(
            "SELECT COUNT(*) FROM dispute_messages 
             WHERE dispute_id = ? AND user_id != ? AND is_read = 0"
        );
        $result->execute([$disputeId, $userId]);
        return (int)$result->fetchColumn();
    }
}
