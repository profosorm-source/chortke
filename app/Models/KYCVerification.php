<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class KYCVerification extends Model {
/**
     * ایجاد درخواست KYC جدید
     * خروجی: id یا false
     */
    public function create(array $data): int|false
    {
        $now = \date('Y-m-d H:i:s');

        $sql = "INSERT INTO kyc_verifications (
                    user_id, verification_image, national_code, birth_date, status,
                    ip_address, user_agent, device_fingerprint,
                    submitted_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->query($sql, [
            (int)$data['user_id'],
            (string)$data['verification_image'],
            $data['national_code'] ?? null,
            $data['birth_date'] ?? null,
            $data['status'] ?? 'pending',
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null,
            $data['device_fingerprint'] ?? null,
            $now,
            $now,
            $now,
        ]);

        return $stmt ? (int)$this->db->lastInsertId() : false;
    }

    /**
     * یافتن KYC بر اساس ID
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->query("SELECT * FROM kyc_verifications WHERE id = ? LIMIT 1", [$id]);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    /**
     * یافتن KYC بر اساس user_id
     */
    public function findByUserId(int $userId): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM kyc_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    /**
     * بروزرسانی وضعیت KYC
     */
    public function updateStatus(int $id, string $status, ?string $reason = null): bool
    {
        $data = [
            'status' => $status,
            'reviewed_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];

        if ($status === 'verified') {
            $data['verified_at'] = \date('Y-m-d H:i:s');
            $data['expires_at'] = \date('Y-m-d H:i:s', \strtotime('+1 year'));
            $data['rejection_reason'] = null;
        }

        if ($status === 'rejected') {
            $data['rejection_reason'] = $reason;
        }

        return $this->update($id, $data);
    }

    /**
     * بروزرسانی عمومی
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            $fields[] = "{$key} = ?";
            $values[] = $value;
        }

        $values[] = $id;

        $sql = "UPDATE kyc_verifications SET " . \implode(', ', $fields) . " WHERE id = ?";

        $stmt = $this->db->query($sql, $values);
        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }
        return (bool)$stmt;
    }

    /**
     * دریافت لیست KYC با فیلتر + صف‌بندی
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT k.*, u.full_name, u.email
                FROM kyc_verifications k
                JOIN users u ON k.user_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND k.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR k.national_code LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        // صف بررسی + آرشیو
        $sql .= "
            ORDER BY
                CASE WHEN k.status IN ('pending','under_review') THEN 0 ELSE 1 END ASC,
                CASE WHEN k.status IN ('pending','under_review') THEN k.created_at END ASC,
                CASE WHEN k.status NOT IN ('pending','under_review')
                    THEN IFNULL(k.reviewed_at, k.created_at) END DESC,
                k.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    /**
     * شمارش کل KYC
     */
    public function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM kyc_verifications k
                JOIN users u ON k.user_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND k.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR k.national_code LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    /**
     * حذف تصویر KYC برای امنیت (پس از تأیید) - یکدست با UploadService
     */
    public function deleteVerificationImage(int $id): bool
    {
        $kyc = $this->find($id);
        if (!$kyc) return false;

        $file = (string)($kyc->verification_image ?? '');
        if ($file !== '' && $file !== '[DELETED]') {
            try {
                $uploadService = app(\App\Services\UploadService::class);

                // اگر فقط filename ذخیره شده، ما prefix می‌زنیم
                $path = \str_contains($file, '/') ? $file : ('kyc/' . $file);

                // اگر UploadService متد delete دارد
                if (\method_exists($uploadService, 'delete')) {
                    $uploadService->delete($path);
                }
            } catch (\Throwable $e) {
                // fail-safe: حتی اگر حذف فایل شکست خورد، رکورد دیتابیس باید پاکسازی شود
            }
        }

        return $this->update($id, [
            'verification_image' => '[DELETED]',
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
    }

    public function getOldRejected(int $days = 60): array
    {
        $sql = "SELECT id, document_front, document_back, selfie
                FROM kyc_verifications
                WHERE status = 'rejected'
                  AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND documents_deleted = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ==================== ANALYTICS METHODS ====================

    /**
     * آمار KYC
     */
    public function getKycStats(): array
    {
        $row = $this->db->fetch("
            SELECT
                SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN status IN ('pending','under_review') THEN 1 ELSE 0 END) as pending
            FROM kyc_verifications
        ");
        return [
            'verified' => (int)($row->verified ?? 0),
            'pending' => (int)($row->pending ?? 0),
        ];
    }
}