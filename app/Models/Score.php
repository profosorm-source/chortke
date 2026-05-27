<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use App\Enums\ScoreDomain;

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
    protected static string $table = 'score_events';

    public const DOMAIN_FRAUD = 'fraud';
    public const DOMAIN_TASK = 'task';
    public const DOMAIN_SOCIAL_TRUST = 'social_trust';
    public const DOMAIN_REFERRAL = 'referral';
    public const DOMAIN_ACTIVITY = 'activity';
    public const DOMAIN_LOYALTY = 'loyalty';

    public static function normalizeDomain(string $domain): string
    {
        $normalized = ScoreDomain::normalize($domain);
        if (!ScoreDomain::isValid($normalized)) {
            throw new \InvalidArgumentException("Unsupported score domain: {$domain}");
        }
        return $normalized;
    }

    public static function allowedDomains(): array
    {
        return ScoreDomain::values();
    }

    // ==========================================
    // Event Management (from UserScoreEvent)
    // ==========================================

    public function createEvent(int $userId, string $domain, string $source, float $delta, array $meta = []): bool
    {
        $domain = self::normalizeDomain($domain);
        
        $redis = class_exists('\Core\Cache') ? \Core\Cache::getInstance()->redis() : null;
        if ($redis) {
            $payload = json_encode([
                'entity_type' => 'user',
                'entity_id'   => $userId,
                'domain'      => $domain,
                'delta'       => $delta,
                'source'      => $source,
                'meta_json'   => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'created_at'  => date('Y-m-d H:i:s')
            ]);
            $redis->rPush('chortke:score_events_buffer', $payload);
            return true;
        }

        $stmt = $this->db->prepare("
            INSERT INTO score_events (entity_type, entity_id, domain, delta, source, meta_json, created_at)
            VALUES ('user', ?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $userId,
            $domain,
            $delta,
            $source,
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public function getEventsByUser(int $userId, ?string $domain = null, int $limit = 200): array
    {
        $limit = \max(1, (int)$limit);
        $domain = $domain !== null ? self::normalizeDomain($domain) : null;
        if ($domain === null) {
            $stmt = $this->db->prepare("
                SELECT id, domain, source, delta, meta_json, created_at FROM score_events
                WHERE entity_type = 'user' AND entity_id = ?
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
        } else {
            $stmt = $this->db->prepare("
                SELECT id, domain, source, delta, meta_json, created_at FROM score_events
                WHERE entity_type = 'user' AND entity_id = ? AND domain = ?
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
        $domain = self::normalizeDomain($domain);
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
        $data['domain'] = self::normalizeDomain((string)($data['domain'] ?? ''));
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
        $data['domain'] = self::normalizeDomain((string)($data['domain'] ?? ''));
        
        $redis = class_exists('\Core\Cache') ? \Core\Cache::getInstance()->redis() : null;
        if ($redis) {
            $payload = json_encode([
                'entity_type' => $data['entity_type'],
                'entity_id'   => $data['entity_id'],
                'domain'      => $data['domain'],
                'delta'       => $data['delta'],
                'source'      => $data['source'],
                'meta_json'   => json_encode($data['meta'] ?? []),
                'created_at'  => date('Y-m-d H:i:s')
            ]);
            $redis->rPush('chortke:score_events_buffer', $payload);
            return true;
        }

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
        $domain = self::normalizeDomain($domain);
        $stmt = $this->db->prepare("
            SELECT SUM(delta) FROM score_events
            WHERE entity_id = ? AND entity_type = ? AND domain = ?
        ");
        $stmt->execute([$entityId, $entityType, $domain]);
        return (float)$stmt->fetchColumn();
    }

    public function getDomainScore(int $userId, string $domain): float
    {
        $domain = self::normalizeDomain($domain);
        // FOR UPDATE lock on score_events removed to prevent Database Contention.
        // We now rely on Redis buffer and async flushing for score updates, 
        // so row-level locking for reading sum is both unnecessary and harmful to performance.

        // جدول یکپارچه score_events
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(delta), 0.0) FROM score_events
            WHERE entity_id = ? AND entity_type = 'user' AND domain = ?
        ");
        $stmt->execute([$userId, $domain]);
        return (float)$stmt->fetchColumn();
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
            SELECT id, domain, source, delta, meta_json, created_at FROM score_events
            WHERE entity_type = 'user' AND entity_id = ?
            UNION ALL
            SELECT id, domain, source, delta, meta_json, created_at FROM user_score_events
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $userId, $limit]);

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
            $redis = class_exists('\Core\Cache') ? \Core\Cache::getInstance()->redis() : null;
            if ($redis) {
                $payload = json_encode([
                    'entity_type' => 'user',
                    'entity_id'   => (int)$adj['user_id'],
                    'domain'      => (string)$adj['domain'],
                    'delta'       => 0,
                    'source'      => 'admin_adjustment_revoke',
                    'meta_json'   => json_encode([
                        'adjustment_id' => $adjustmentId,
                        'reason' => $reason,
                        'admin_id' => $adminId,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at'  => date('Y-m-d H:i:s')
                ]);
                $redis->rPush('chortke:score_events_buffer', $payload);
            } else {
                // Log the event (Unified)
                $ev = $this->db->prepare("
                    INSERT INTO score_events (entity_type, entity_id, domain, delta, source, meta_json, created_at)
                    VALUES ('user', ?, ?, ?, ?, ?, NOW())
                ");
                $ev->execute([
                    (int)$adj['user_id'],
                    (string)$adj['domain'],
                    0,
                    'admin_adjustment_revoke',
                    json_encode([
                        'adjustment_id' => $adjustmentId,
                        'reason' => $reason,
                        'admin_id' => $adminId,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        return $ok;
    }

    /**
     * Flush buffered events from Redis into DB using Bulk Insert.
     * Called via CronJob.
     */
    public function flushBuffer(int $batchSize = 1000): int
    {
        $redis = class_exists('\Core\Cache') ? \Core\Cache::getInstance()->redis() : null;
        if (!$redis) {
            return 0;
        }

        $items = $redis->lRange('chortke:score_events_buffer', 0, $batchSize - 1);
        if (empty($items)) {
            return 0;
        }

        // Truncate the buffer
        $redis->lTrim('chortke:score_events_buffer', count($items), -1);

        $values = [];
        $params = [];
        foreach ($items as $itemStr) {
            $item = json_decode($itemStr, true);
            if (!$item) continue;

            $values[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push($params,
                $item['entity_type'],
                $item['entity_id'],
                $item['domain'],
                $item['delta'],
                $item['source'],
                $item['meta_json'],
                $item['created_at']
            );
        }

        if (empty($values)) {
            return 0;
        }

        $sql = "INSERT INTO score_events (entity_type, entity_id, domain, delta, source, meta_json, created_at) VALUES " . implode(', ', $values);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return count($items);
    }
}
