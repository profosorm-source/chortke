<?php

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * SeoExecution — اجرای تسک توسط Worker
 * جدول: seo_executions
 */
class SeoExecution extends Model
{
    public int     $id;
    public int     $ad_id;              // seo_ads.id
    public int     $user_id;            // انجام‌دهنده
    public float   $time_score;         // 0-30
    public float   $scroll_score;       // 0-25
    public float   $interaction_score;  // 0-25
    public float   $quality_score;      // 0-20
    public float   $final_score;        // 0-100
    public float   $payout_amount;      // محاسبه شده
    public string  $status;             // started|completed|rejected|fraud
    public ?string $engagement_data;    // JSON
    public ?string $fraud_flags;        // JSON
    public string  $ip_address;
    public ?string $device_fingerprint;
    public string  $started_at;
    public ?string $completed_at;
    public string  $created_at;
    public ?string $updated_at;

    // --------------------------------------------------------
    // READ
    // --------------------------------------------------------

    public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("SELECT * FROM seo_executions WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** پیدا کردن با تایید مالکیت */
    public function findByUser(int $id, int $userId): ?self
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM seo_executions WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** تاریخچه اجراها توسط یک کاربر */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, a.title AS ad_title, a.keyword
             FROM seo_executions e
             LEFT JOIN seo_ads a ON a.id = e.ad_id
             WHERE e.user_id = ?
             ORDER BY e.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** شمارش کل اجراها توسط کاربر */
    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** اجراهای امروز کاربر */
    public function countByUserToday(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions
             WHERE user_id = ? AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** بررسی تکراری بودن (یک کاربر برای یک آگهی در روز) */
    public function existsByAdAndUserToday(int $adId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions
             WHERE ad_id = ? AND user_id = ? AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$adId, $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** محدودیت ساعتی کاربر */
    public function countByUserLastHour(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** محدودیت IP ساعتی */
    public function countByIPLastHour(string $ip): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions
             WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn();
    }

    /** آمار کاربر */
    public function getUserStats(int $userId): object
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN payout_amount ELSE 0 END), 0) AS total_earned,
                COALESCE(AVG(CASE WHEN status = 'completed' THEN final_score END), 0) AS avg_score
             FROM seo_executions
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: (object)[
            'total_executions' => 0,
            'completed' => 0,
            'total_earned' => 0,
            'avg_score' => 0
        ];
    }

    /** آمار آگهی برای تبلیغ‌دهنده */
    public function getAdStats(int $adId): object
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN payout_amount ELSE 0 END), 0) AS total_spent,
                COALESCE(AVG(CASE WHEN status = 'completed' THEN final_score END), 0) AS avg_score,
                COUNT(CASE WHEN fraud_flags IS NOT NULL THEN 1 END) AS fraud_count
             FROM seo_executions
             WHERE ad_id = ?"
        );
        $stmt->execute([$adId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: (object)[
            'total_executions' => 0,
            'completed' => 0,
            'total_spent' => 0,
            'avg_score' => 0,
            'fraud_count' => 0
        ];
    }

    // --------------------------------------------------------
    // WRITE
    // --------------------------------------------------------

    public function create(array $d): ?self
    {
        $stmt = $this->db->prepare(
            "INSERT INTO seo_executions
             (ad_id, user_id, status, ip_address, device_fingerprint, started_at)
             VALUES (?, ?, 'started', ?, ?, NOW())"
        );
        
        $ok = $stmt->execute([
            (int)$d['ad_id'],
            (int)$d['user_id'],
            $d['ip_address'] ?? get_client_ip(),
            $d['device_fingerprint'] ?? null,
        ]);

        return $ok ? $this->find((int)$this->db->lastInsertId()) : null;
    }

    /** تکمیل اجرا با امتیازها */
    public function complete(int $id, array $scores, float $payout): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE seo_executions
             SET time_score = ?,
                 scroll_score = ?,
                 interaction_score = ?,
                 quality_score = ?,
                 final_score = ?,
                 payout_amount = ?,
                 engagement_data = ?,
                 status = 'completed',
                 completed_at = NOW(),
                 updated_at = NOW()
             WHERE id = ? AND status = 'started'"
        );

        return $stmt->execute([
            (float)$scores['time_score'],
            (float)$scores['scroll_score'],
            (float)$scores['interaction_score'],
            (float)$scores['quality_score'],
            (float)$scores['final_score'],
            (float)$payout,
            json_encode($scores['engagement_data'] ?? []),
            $id
        ]);
    }

    /** علامت‌گذاری به عنوان تقلب */
    public function markAsFraud(int $id, array $flags): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE seo_executions
             SET status = 'fraud',
                 fraud_flags = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );

        return $stmt->execute([json_encode($flags), $id]);
    }

    /** رد شدن */
    public function reject(int $id, string $reason): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE seo_executions
             SET status = 'rejected',
                 fraud_flags = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );

        return $stmt->execute([json_encode(['reason' => $reason]), $id]);
    }

    // --------------------------------------------------------
    // PRIVATE
    // --------------------------------------------------------

    private function hydrate(array $row): self
    {
        $o = new self();
        $o->id                  = (int)   $row['id'];
        $o->ad_id               = (int)   $row['ad_id'];
        $o->user_id             = (int)   $row['user_id'];
        $o->time_score          = (float) ($row['time_score']         ?? 0);
        $o->scroll_score        = (float) ($row['scroll_score']       ?? 0);
        $o->interaction_score   = (float) ($row['interaction_score']  ?? 0);
        $o->quality_score       = (float) ($row['quality_score']      ?? 0);
        $o->final_score         = (float) ($row['final_score']        ?? 0);
        $o->payout_amount       = (float) ($row['payout_amount']      ?? 0);
        $o->status              =         $row['status'];
        $o->engagement_data     =         $row['engagement_data']     ?? null;
        $o->fraud_flags         =         $row['fraud_flags']         ?? null;
        $o->ip_address          =         $row['ip_address'];
        $o->device_fingerprint  =         $row['device_fingerprint']  ?? null;
        $o->started_at          =         $row['started_at'];
        $o->completed_at        =         $row['completed_at']        ?? null;
        $o->created_at          =         $row['created_at'];
        $o->updated_at          =         $row['updated_at']          ?? null;
        return $o;
    }
}
