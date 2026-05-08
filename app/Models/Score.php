<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Score Model - مدل اشتراکی امتیازات
 * 
 * Consolidated from: UserScoreEvent.php, UserScoreAdjustment.php, Score.php
 * 
 * این مدل تمام عملیات امتیازدهی را مدیریت می‌کند:
 * - ثبت رویدادهای امتیازدهی (events)
 * - اعمال تنظیمات امتیاز (adjustments)
 * - محاسبه امتیازات موثر (effective scores)
 * 
 * جداول: score_events, user_score_adjustments (legacy), user_score_events (legacy)
 */
class Score extends Model
{
    // ==========================================
    // Event Management (from UserScoreEvent)
    // ==========================================

    /**
     * ثبت رویداد امتیازدهی جدید
     */
    public function createEvent(int $userId, string $domain, string $source, float $delta, array $meta = []): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_score_events (user_id, domain, source, delta, meta_json, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $userId,
            $domain,
            $source,
            $delta,
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * دریافت رویدادهای امتیازدهی کاربر
     */
    public function getEventsByUser(int $userId, ?string $domain = null, int $limit = 200): array
    {
        if ($domain === null) {
            $stmt = $this->db->prepare("
                SELECT * FROM user_score_events 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM user_score_events 
                WHERE user_id = ? AND domain = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$userId, $domain, $limit]);
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ==========================================
    // Adjustment Management (from UserScoreAdjustment)
    // ==========================================

    /**
     * دریافت تنظیمات فعال امتیازدهی
     */
    public function getActiveAdjustments(int $userId, string $domain): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_score_adjustments
            WHERE user_id = ? AND domain = ? AND is_active = 1
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId, $domain]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * ایجاد تنظیم امتیاز جدید
     */
    public function createAdjustment(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_score_adjustments 
            (user_id, domain, operation, value, reason, expires_at, created_by, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");

        return $stmt->execute([
            $data['user_id'],
            $data['domain'],
            $data['operation'],
            $data['value'],
            $data['reason'],
            $data['expires_at'] ?? null,
            $data['created_by'],
        ]);
    }

    /**
     * دریافت تنظیمات امتیاز کاربر
     */
    public function getAdjustmentsByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_score_adjustments 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 200
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ==========================================
    // Unified Score Events (New)
    // ==========================================

    /**
     * ثبت رویداد امتیازدهی unified
     */
    public function addEvent(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO score_events (entity_type, entity_id, domain, delta, source, meta_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $data['entity_type'], // user, profile, etc
            $data['entity_id'],
            $data['domain'],     // fraud, loyalty, influencer, social_trust
            $data['delta'],
            $data['source'],
            json_encode($data['meta'] ?? [])
        ]);
    }

    /**
     * دریافت امتیاز کل unified
     */
    public function getTotal(int $entityId, string $entityType, string $domain): float
    {
        $stmt = $this->db->prepare("
            SELECT SUM(delta) FROM score_events
            WHERE entity_id = ? AND entity_type = ? AND domain = ?
        ");
        $stmt->execute([$entityId, $entityType, $domain]);
        return (float)$stmt->fetchColumn();
    }

    // ==========================================
    // Trust Score Management (from TrustScoreService)
    // ==========================================

    /**
     * دریافت trust score کاربر
     */
    public function getTrustScore(int $userId): float
    {
        $stmt = $this->db->prepare("
            SELECT trust_score FROM user_trust_scores 
            WHERE user_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $score = $stmt->fetchColumn();

        return $score !== false ? (float)$score : 50.0; // Default 50
    }

    /**
     * بروزرسانی trust score کاربر
     */
    public function updateTrustScore(int $userId, float $score): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_trust_scores (user_id, trust_score, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE trust_score = VALUES(trust_score), updated_at = NOW()
        ");

        return $stmt->execute([$userId, $score]);
    }

    /**
     * دریافت آمار هفتگی اجرا برای trust score
     */
    public function getWeeklyExecutionStats(int $userId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' AND final_score >= 80 THEN 1 ELSE 0 END) as good_tasks,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'soft_approved' THEN 1 ELSE 0 END) as soft_approved,
                AVG(final_score) as avg_score
            FROM task_executions
            WHERE executor_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$userId]);

        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /**
     * ذخیره snapshot trust score
     */
    public function saveTrustSnapshot(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_trust_snapshots 
            (user_id, trust_score, week_good_tasks, week_rejected, week_soft, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $data['user_id'],
            $data['trust_score'],
            $data['week_good_tasks'],
            $data['week_rejected'],
            $data['week_soft']
        ]);
    }

    // ==========================================
    // Legacy Methods (for backward compatibility)
    // ==========================================

    public function getTaskRawRisk(int $userId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(AVG(fraud_score), 0) AS avg_score
            FROM task_executions
            WHERE executor_id = ?
        ");
        $stmt->execute([$userId]);

        return (float)$stmt->fetchColumn();
    }

    public function getRecentEvents(int $userId, int $limit = 50): array
    {
        $limit = \max(1, (int)$limit);
        $stmt = $this->db->prepare("
            SELECT id, domain, source, delta, meta_json, created_at
            FROM user_score_events
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function revokeAdjustment(int $adjustmentId, int $adminId, string $reason): bool
    {
        // First get the adjustment details
        $find = $this->db->prepare("
            SELECT id, user_id, domain, operation, value
            FROM user_score_adjustments
            WHERE id = ?
            LIMIT 1
        ");
        $find->execute([$adjustmentId]);
        $adj = $find->fetch(\PDO::FETCH_ASSOC);

        if (!$adj) {
            return false;
        }

        // Deactivate the adjustment
        $stmt = $this->db->prepare("
            UPDATE user_score_adjustments
            SET is_active = 0
            WHERE id = ?
            LIMIT 1
        ");
        $ok = $stmt->execute([$adjustmentId]);

        if ($ok) {
            // Log the event
            $ev = $this->db->prepare("
                INSERT INTO user_score_events (user_id, domain, source, delta, meta_json, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $ev->execute([
                (int)$adj['user_id'],
                (string)$adj['domain'],
                'admin_adjustment_revoke',
                0,
                json_encode([
                    'adjustment_id' => $adjustmentId,
                    'reason' => $reason,
                    'admin_id' => $adminId,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $ok;
    }
}
