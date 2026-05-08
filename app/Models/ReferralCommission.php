<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class ReferralCommission extends Model {
private function fetchOne(string $sql, array $params = []): ?object
    {
        $stmt = $this->db->query($sql, $params);
        if (!$stmt) return null;

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    private function fetchAllRows(string $sql, array $params = []): array
    {
        $stmt = $this->db->query($sql, $params);
        if (!$stmt) return [];

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    private function fetchColumnValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->query($sql, $params);
        if (!$stmt) return null;

        return $stmt->fetchColumn();
    }

    public function find(int $id): ?object
    {
        $sql = "
            SELECT rc.*,
                referrer.full_name AS referrer_name,
                referrer.email AS referrer_email,
                referred.full_name AS referred_name,
                referred.email AS referred_email
            FROM referral_commissions rc
            LEFT JOIN users referrer ON referrer.id = rc.referrer_id
            LEFT JOIN users referred ON referred.id = rc.referred_id
            WHERE rc.id = ?
        ";

        return $this->fetchOne($sql, [$id]);
    }

    public function findByIdempotencyKey(string $key): ?object
    {
        $sql = "SELECT * FROM referral_commissions WHERE idempotency_key = ? LIMIT 1";
        return $this->fetchOne($sql, [$key]);
    }

    public function create(array $data): ?object
    {
        $now = \date('Y-m-d H:i:s');

        $sql = "
            INSERT INTO referral_commissions
            (referrer_id, referred_id, source_type, source_id, source_amount,
             commission_percent, commission_amount, currency, status,
             idempotency_key, metadata, ip_address, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $metadataJson = null;
        if (isset($data['metadata'])) {
            $metadataJson = \is_array($data['metadata'])
                ? \json_encode($data['metadata'], JSON_UNESCAPED_UNICODE)
                : (string)$data['metadata'];
        }

        $ok = $this->db->query($sql, [
            (int)$data['referrer_id'],
            (int)$data['referred_id'],
            (string)$data['source_type'],
            $data['source_id'] ?? null,
            (float)$data['source_amount'],
            (float)$data['commission_percent'],
            (float)$data['commission_amount'],
            (string)($data['currency'] ?? 'irt'),
            (string)($data['status'] ?? 'pending'),
            (string)$data['idempotency_key'],
            $metadataJson,
            (string)($data['ip_address'] ?? (function_exists('get_client_ip') ? get_client_ip() : '')),
            $now,
            $now,
        ]);

        if (!$ok) return null;

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $this->find($id) : null;
    }

    public function updateStatus(int $id, string $status, ?string $transactionId = null): bool
    {
        $paidAt = ($status === 'paid') ? \date('Y-m-d H:i:s') : null;

        $sql = "
            UPDATE referral_commissions
            SET status = ?, paid_at = ?, transaction_id = ?, updated_at = NOW()
            WHERE id = ?
        ";

        $stmt = $this->db->query($sql, [$status, $paidAt, $transactionId, $id]);
        if ($stmt instanceof \PDOStatement) return $stmt->rowCount() >= 0;

        return (bool)$stmt;
    }

    public function getByReferrer(int $referrerId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $where = ["rc.referrer_id = ?"];
        $params = [$referrerId];

        if (!empty($filters['status'])) {
            $where[] = "rc.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['source_type'])) {
            $where[] = "rc.source_type = ?";
            $params[] = $filters['source_type'];
        }

        if (!empty($filters['currency'])) {
            $where[] = "rc.currency = ?";
            $params[] = $filters['currency'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "rc.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "rc.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = \implode(' AND ', $where);

        $sql = "
            SELECT rc.*,
                referred.full_name AS referred_name,
                referred.email AS referred_email
            FROM referral_commissions rc
            LEFT JOIN users referred ON referred.id = rc.referred_id
            WHERE {$whereStr}
            ORDER BY rc.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        return $this->fetchAllRows($sql, $params);
    }

    public function countByReferrer(int $referrerId, array $filters = []): int
    {
        $where = ["referrer_id = ?"];
        $params = [$referrerId];

        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['source_type'])) {
            $where[] = "source_type = ?";
            $params[] = $filters['source_type'];
        }

        if (!empty($filters['currency'])) {
            $where[] = "currency = ?";
            $params[] = $filters['currency'];
        }

        $whereStr = \implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM referral_commissions WHERE {$whereStr}";

        return (int)($this->fetchColumnValue($sql, $params) ?? 0);
    }

    public function getReferrerStats(int $referrerId): ?object
    {
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN currency='irt' AND status='paid' THEN commission_amount ELSE 0 END), 0) AS total_earned_irt,
                COALESCE(SUM(CASE WHEN currency='usdt' AND status='paid' THEN commission_amount ELSE 0 END), 0) AS total_earned_usdt,
                COALESCE(SUM(CASE WHEN currency='irt' AND status='pending' THEN commission_amount ELSE 0 END), 0) AS pending_irt,
                COALESCE(SUM(CASE WHEN currency='usdt' AND status='pending' THEN commission_amount ELSE 0 END), 0) AS pending_usdt,
                COUNT(CASE WHEN status='paid' THEN 1 END) AS paid_count,
                COUNT(CASE WHEN status='pending' THEN 1 END) AS pending_count,
                COUNT(*) AS total_count
            FROM referral_commissions
            WHERE referrer_id = ?
        ";

        return $this->fetchOne($sql, [$referrerId]);
    }

    public function getReferredUsers(int $referrerId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "
            SELECT
                u.id,
                u.full_name,
                u.email,
                u.created_at AS joined_at,
                u.status,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) AS earned_irt,
                COALESCE(SUM(CASE WHEN rc.currency='usdt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) AS earned_usdt,
                COUNT(rc.id) AS commission_count
            FROM users u
            LEFT JOIN referral_commissions rc
                ON rc.referred_id = u.id AND rc.referrer_id = ?
            WHERE u.referred_by = ? AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        return $this->fetchAllRows($sql, [$referrerId, $referrerId]);
    }

    public function countReferredUsers(int $referrerId): int
    {
        $sql = "SELECT COUNT(*) FROM users WHERE referred_by = ? AND deleted_at IS NULL";
        return (int)($this->fetchColumnValue($sql, [$referrerId]) ?? 0);
    }

    public function todaySignupCount(int $referrerId): int
    {
        $sql = "
            SELECT COUNT(*) FROM users
            WHERE referred_by = ?
              AND DATE(created_at) = CURDATE()
              AND deleted_at IS NULL
        ";
        return (int)($this->fetchColumnValue($sql, [$referrerId]) ?? 0);
    }

    public function todaySignupCountByIp(string $ip): int
    {
        $sql = "
            SELECT COUNT(*) FROM referral_activity_logs
            WHERE action = 'signup'
              AND ip_address = ?
              AND DATE(created_at) = CURDATE()
        ";
        return (int)($this->fetchColumnValue($sql, [$ip]) ?? 0);
    }
	
	public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
{
    $limit  = \max(1, (int)$limit);
    $offset = \max(0, (int)$offset);

    $sql = "SELECT rc.*,
                   ref.full_name AS referrer_name, ref.email AS referrer_email,
                   r.full_name AS referred_name, r.email AS referred_email
            FROM referral_commissions rc
            LEFT JOIN users ref ON ref.id = rc.referrer_id
            LEFT JOIN users r   ON r.id   = rc.referred_id
            WHERE 1=1";

    $params = [];

    if (!empty($filters['status'])) {
        $sql .= " AND rc.status = ?";
        $params[] = $filters['status'];
    }
    if (!empty($filters['source_type'])) {
        $sql .= " AND rc.source_type = ?";
        $params[] = $filters['source_type'];
    }
    if (!empty($filters['currency'])) {
        $sql .= " AND rc.currency = ?";
        $params[] = $filters['currency'];
    }
    if (!empty($filters['search'])) {
        $sql .= " AND (
            ref.full_name LIKE ? OR ref.email LIKE ?
            OR r.full_name LIKE ? OR r.email LIKE ?
            OR rc.idempotency_key LIKE ?
        )";
        $s = '%' . $filters['search'] . '%';
        $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    }

    $sql .= " ORDER BY rc.created_at DESC LIMIT {$limit} OFFSET {$offset}";

    // اینجا از wrapper های داخل ReferralCommission استفاده کن اگر داری؛ در غیر اینصورت:
    $stmt = $this->db->query($sql, $params);
    return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
}

public function adminCount(array $filters = []): int
{
    $sql = "SELECT COUNT(*) as total
            FROM referral_commissions rc
            LEFT JOIN users ref ON ref.id = rc.referrer_id
            LEFT JOIN users r   ON r.id   = rc.referred_id
            WHERE 1=1";

    $params = [];

    if (!empty($filters['status'])) {
        $sql .= " AND rc.status = ?";
        $params[] = $filters['status'];
    }
    if (!empty($filters['source_type'])) {
        $sql .= " AND rc.source_type = ?";
        $params[] = $filters['source_type'];
    }
    if (!empty($filters['currency'])) {
        $sql .= " AND rc.currency = ?";
        $params[] = $filters['currency'];
    }
    if (!empty($filters['search'])) {
        $sql .= " AND (
            ref.full_name LIKE ? OR ref.email LIKE ?
            OR r.full_name LIKE ? OR r.email LIKE ?
            OR rc.idempotency_key LIKE ?
        )";
        $s = '%' . $filters['search'] . '%';
        $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    }

    $stmt = $this->db->query($sql, $params);
    $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

    return (int)($row->total ?? 0);
}

public function globalStats(): object
{
    $stmt = $this->db->query("
        SELECT
          COUNT(*) as total,
          SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_count,
          SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid_count,
          COALESCE(SUM(CASE WHEN currency='irt'  AND status='paid' THEN commission_amount ELSE 0 END),0) as total_paid_irt,
          COALESCE(SUM(CASE WHEN currency='usdt' AND status='paid' THEN commission_amount ELSE 0 END),0) as total_paid_usdt
        FROM referral_commissions
    ");

    return $stmt ? ($stmt->fetch(\PDO::FETCH_OBJ) ?: (object)[]) : (object)[];
}

public function topReferrers(string $currency = 'irt', int $limit = 5): array
{
    $limit = \max(1, (int)$limit);

    $stmt = $this->db->query("
        SELECT u.id, u.full_name, u.email,
               COALESCE(SUM(rc.commission_amount),0) as total_commission
        FROM referral_commissions rc
        JOIN users u ON u.id = rc.referrer_id
        WHERE rc.status='paid' AND rc.currency = ?
        GROUP BY u.id
        ORDER BY total_commission DESC
        LIMIT {$limit}
    ", [$currency]);

    return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
}
}