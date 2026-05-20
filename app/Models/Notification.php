<?php

namespace App\Models;

use Core\Model;

class Notification extends Model
{
    protected static string $table = 'notifications';

    public function __construct(\Core\Database $db)
    {
        parent::__construct($db);
    }

    // ─── انواع نوتیفیکیشن ────────────────────────────────────────────────────
    public const TYPE_SYSTEM     = 'system';
    public const TYPE_DEPOSIT    = 'deposit';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_TASK       = 'task';
    public const TYPE_KYC        = 'kyc';
    public const TYPE_LOTTERY    = 'lottery';
    public const TYPE_REFERRAL   = 'referral';
    public const TYPE_SECURITY   = 'security';
    public const TYPE_INVESTMENT = 'investment';
    public const TYPE_INFO       = 'info';
    public const TYPE_MARKETING  = 'marketing';

    // ─── کانال‌های ارسال ──────────────────────────────────────────────────────
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_PUSH   = 'push';
    public const CHANNEL_EMAIL  = 'email';
    public const CHANNEL_SMS    = 'sms';

    // ─── اولویت‌ها ────────────────────────────────────────────────────────────
    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    /**
     * ایجاد نوتیفیکیشن
     */
    public function create(array $data): int|false
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->query(
            "INSERT INTO notifications
                (user_id, type, title, message, data, action_url, action_text,
                 priority, is_read, is_archived, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)",
            [
                $data['user_id']      ?? null,
                $data['type']         ?? self::TYPE_SYSTEM,
                $data['title']        ?? '',
                $data['message']      ?? '',
                isset($data['data'])
                    ? (is_array($data['data']) ? json_encode($data['data'], JSON_UNESCAPED_UNICODE) : $data['data'])
                    : null,
                $data['action_url']   ?? null,
                $data['action_text']  ?? null,
                $data['priority']     ?? self::PRIORITY_NORMAL,
                $data['expires_at']   ?? null,
                $now,
            ]
        );

        if (!$stmt) {
            return false;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : false;
    }

    /**
     * آخرین نوتیفیکیشن‌های کاربر
     */
    public function getLatestForUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(200, $limit));

        $stmt = $this->db->prepare(
            "SELECT *
             FROM notifications
             WHERE user_id     = ?
               AND is_archived = 0
               AND (expires_at  IS NULL OR expires_at  >  NOW())
             ORDER BY id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * نوتیفیکیشن‌های کاربر با فیلتر، مرتب‌سازی اولویت و pagination
     */
    public function getUserNotifications(
        int  $userId,
        bool $onlyUnread = false,
        int  $limit      = 20,
        int  $offset     = 0
    ): array {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $sql    = "SELECT *
                   FROM notifications
                   WHERE user_id     = ?
                     AND is_archived = 0
                     AND (expires_at  IS NULL OR expires_at  >  NOW())";
        $params = [$userId];

        if ($onlyUnread) {
            $sql .= " AND is_read = 0";
        }

        $sql .= " ORDER BY
                    CASE priority
                      WHEN 'urgent' THEN 4
                      WHEN 'high'   THEN 3
                      WHEN 'normal' THEN 2
                      WHEN 'low'    THEN 1
                      ELSE 0
                    END DESC,
                    created_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue($index++, $limit, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function getNewNotificationsAfterId(int $userId, int $lastId, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));

        $stmt = $this->db->prepare(
            "SELECT *
             FROM notifications
             WHERE user_id     = ?
               AND id          > ?
               AND (expires_at  IS NULL OR expires_at  >  NOW())
             ORDER BY id ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $lastId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تعداد کل (برای pagination)
     */
    public function countUserNotifications(int $userId, bool $onlyUnread = false): int
    {
        $sql    = "SELECT COUNT(*) AS total
                   FROM notifications
                   WHERE user_id     = ?
                     AND is_archived = 0
                     AND (expires_at  IS NULL OR expires_at  >  NOW())";
        $params = [$userId];

        if ($onlyUnread) {
            $sql .= " AND is_read = 0";
        }

        $row = $this->db->query($sql, $params)->fetch(\PDO::FETCH_OBJ);
        return (int)($row->total ?? 0);
    }

    /**
     * تعداد خوانده‌نشده
     */
    public function countUnread(int $userId): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total
             FROM notifications
             WHERE user_id     = ?
               AND is_read     = 0
               AND is_archived = 0
               AND (expires_at  IS NULL OR expires_at  >  NOW())",
            [$userId]
        )->fetch(\PDO::FETCH_OBJ);

        return (int)($row->total ?? 0);
    }

    /** alias */
    public function getUnreadCount(int $userId): int
    {
        return $this->countUnread($userId);
    }

    /**
     * علامت خواندن یک نوتیفیکیشن
     */
    public function markAsRead(int $id, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?
             WHERE id = ? AND user_id = ?",
            [$now, $id, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * علامت خواندن همه → bool
     */
    public function markAllAsRead(int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?
             WHERE user_id = ? AND is_read = 0",
            [$now, $userId]
        );
        return $stmt instanceof \PDOStatement;
    }

    /**
     * تعداد رکوردهای markAllAsRead
     */
    public function markAllAsReadCount(int $userId): int
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?
             WHERE user_id = ? AND is_read = 0",
            [$now, $userId]
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /**
     * ثبت کلیک (analytics)
     */
    public function recordClick(int $id, int $userId): bool
    {
        return true;
    }

    /**
     * آرشیو کردن
     */
    public function archive(int $notificationId, int $userId): bool
    {
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_archived = 1
             WHERE id = ? AND user_id = ?",
            [$notificationId, $userId]
        );
        return $stmt instanceof \PDOStatement;
    }

    /**
     * حذف منطقی (soft delete)
     */
    public function softDelete(int $notificationId, int $userId): bool
    {
        return $this->archive($notificationId, $userId);
    }

    /**
     * بازیابی نوتیفیکیشن حذف‌شده
     */
    public function restore(int $notificationId, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_deleted = 0, deleted_at = NULL, updated_at = ?
             WHERE id = ? AND user_id = ?",
            [$now, $notificationId, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * آرشیو کردن منقضی‌شده‌ها (cron)
     */
    public function archiveExpired(): int
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_archived = 1, archived_at = ?, updated_at = ?
             WHERE is_archived = 0
               AND is_deleted  = 0
               AND expires_at  IS NOT NULL
               AND expires_at  < ?",
            [$now, $now, $now]
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /** alias backward-compat */
    public function deleteExpired(): int
    {
        return $this->archiveExpired();
    }

    /**
     * حذف فیزیکی نوتیفیکیشن‌های آرشیو‌شده‌ی قدیمی (cron, Section 8.7).
     * فقط رکوردهایی که هم archived و هم بیشتر از $days روز قدیمی‌اند پاک می‌شوند.
     * در batchهای کوچک برای جلوگیری از قفل طولانی.
     */
    public function purgeArchivedOlderThan(int $days = 90, int $batch = 1000): int
    {
        $days  = max(7, min(3650, $days));
        $batch = max(100, min(10000, $batch));

        $totalDeleted = 0;
        $safety = 0;
        while ($safety++ < 1000) {
            $stmt = $this->db->query(
                "DELETE FROM notifications
                 WHERE is_archived = 1
                   AND archived_at IS NOT NULL
                   AND archived_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                 LIMIT " . (int)$batch,
                [$days]
            );
            $deleted = $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
            if ($deleted <= 0) {
                break;
            }
            $totalDeleted += $deleted;
            if ($deleted < $batch) {
                break;
            }
        }
        return $totalDeleted;
    }

    /**
     * علامت‌گذاری sent برای زمانبندی‌شده‌هایی که خیلی قدیمی مانده‌اند ولی sent نشده‌اند
     * (poison-message detection برای scheduled notifications).
     */
    public function markStaleScheduledAsFailed(int $olderThanHours = 48): int
    {
        $olderThanHours = max(1, min(24 * 30, $olderThanHours));
        $stmt = $this->db->query(
            "UPDATE notifications
             SET sent_at = NOW(), updated_at = NOW(), is_archived = 1, archived_at = NOW()
             WHERE scheduled_at IS NOT NULL
               AND sent_at IS NULL
               AND is_deleted = 0
               AND is_archived = 0
               AND scheduled_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$olderThanHours]
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /**
     * نوتیفیکیشن‌های زمان‌بندی‌شده آماده ارسال (cron)
     */
    public function getPendingScheduled(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->prepare(
            "SELECT *
             FROM notifications
             WHERE scheduled_at IS NOT NULL
               AND scheduled_at <= NOW()
               AND sent_at      IS NULL
               AND is_deleted   = 0
             ORDER BY
               CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END DESC,
               scheduled_at ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * علامت‌گذاری به‌عنوان ارسال‌شده
     */
    public function markAsSent(int $id): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications SET sent_at = ?, updated_at = ? WHERE id = ?",
            [$now, $now, $id]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * دریافت بر اساس نوع
     */
    public function getByType(int $userId, string $type, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT *
             FROM notifications
             WHERE user_id     = ?
               AND type        = ?
               AND is_archived = 0
               AND is_deleted  = 0
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $type);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * آمار تجمیعی per-type برای analytics ادمین
     */
    public function getAdminStatsByType(int $days = 30): array
    {
        return $this->db->query(
            "SELECT
                type,
                COUNT(*)                                                            AS total_sent,
                SUM(is_read = 1)                                                    AS total_read,
                SUM(clicked_at IS NOT NULL)                                         AS total_clicked,
                ROUND(AVG(is_read) * 100, 1)                                        AS read_rate,
                ROUND(SUM(clicked_at IS NOT NULL) / NULLIF(COUNT(*), 0) * 100, 1)   AS ctr,
                AVG(CASE WHEN read_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, created_at, read_at) END)            AS avg_time_to_read_sec
             FROM notifications
             WHERE is_deleted  = 0
               AND channel     = 'in_app'
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY type
             ORDER BY total_sent DESC",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * آمار روزانه (sent/read/click per day)
     */
    public function getDailyStats(int $days = 30): array
    {
        return $this->db->query(
            "SELECT
                DATE(created_at)                                                    AS date,
                COUNT(*)                                                            AS sent,
                SUM(is_read = 1)                                                    AS read_count,
                SUM(clicked_at IS NOT NULL)                                         AS click_count,
                ROUND(AVG(is_read) * 100, 1)                                        AS read_rate
             FROM notifications
             WHERE is_deleted  = 0
               AND channel     = 'in_app'
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * آمار per-segment (KYC / level / status)
     */
    public function getStatsBySegment(int $days = 30): array
    {
        return $this->db->query(
            "SELECT
                u.kyc_status,
                u.level,
                u.status                                                            AS user_status,
                COUNT(n.id)                                                         AS total_sent,
                SUM(n.is_read = 1)                                                  AS total_read,
                ROUND(AVG(n.is_read) * 100, 1)                                      AS read_rate,
                ROUND(SUM(n.clicked_at IS NOT NULL) / NULLIF(COUNT(n.id), 0) * 100, 1) AS ctr
             FROM notifications n
             JOIN users u ON u.id = n.user_id
             WHERE n.is_deleted  = 0
               AND n.channel     = 'in_app'
               AND n.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND u.deleted_at  IS NULL
             GROUP BY u.kyc_status, u.level, u.status
             ORDER BY total_sent DESC",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * notification fatigue — کاربران با انباشت بالای نوتیف نخوانده
     */
    public function getHighUnreadUsers(int $threshold = 20, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, COUNT(*) AS unread_count
             FROM notifications
             WHERE is_read    = 0
               AND is_deleted = 0
               AND is_archived = 0
               AND channel    = 'in_app'
             GROUP BY user_id
             HAVING unread_count >= ?
             ORDER BY unread_count DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $threshold, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * گروه‌بندی نوتیفیکیشن‌ها — فقط در لایه نمایش
     * هر رکورد مستقل باقی می‌ماند
     */
    public function getGroupedForUser(int $userId, int $limit = 20): array
    {
        $notifications = $this->getUserNotifications($userId, false, $limit);
        $groups        = [];

        foreach ($notifications as $notif) {
            $key = $notif->group_key ?? ('single_' . $notif->id);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'latest' => $notif,
                    'count'  => 1,
                    'unread' => (int)!$notif->is_read,
                    'ids'    => [$notif->id],
                ];
            } else {
                $groups[$key]['count']++;
                if (!$notif->is_read) {
                    $groups[$key]['unread']++;
                }
                $groups[$key]['ids'][] = $notif->id;
            }
        }

        return array_values($groups);
    }

    // ─── ادغام شده از NotificationModel ──────────────────────────────────────

    public function getActiveChannelsBySeverity(string $severity): array
    {
        return $this->db->table('notification_channels')
            ->where('is_active', '=', 1)
            ->whereJsonContains('alert_levels', $severity)
            ->get();
    }

    public function logHistory(int $channelId, string $type, string $title, string $message, string $status): bool
    {
        return $this->db->table('notification_history')->insert([
            'channel_id' => $channelId,
            'notification_type' => $type,
            'title' => $title,
            'message' => $message,
            'status' => $status,
            'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null
        ]);
    }

    public function getChannel(int $channelId): ?object
    {
        return $this->db->table('notification_channels')
            ->where('id', '=', $channelId)
            ->first();
    }

    public function getOverviewStats(int $days): ?array
    {
        $sql = "SELECT
                    COUNT(*)                                                             AS total_sent,
                    SUM(is_read = 1)                                                     AS total_read,
                    SUM(clicked_at IS NOT NULL)                                          AS total_clicked,
                    COUNT(DISTINCT user_id)                                              AS unique_users,
                    ROUND(AVG(is_read) * 100, 1)                                         AS read_rate,
                    ROUND(SUM(clicked_at IS NOT NULL) / NULLIF(COUNT(*), 0) * 100, 1)    AS ctr,
                    AVG(CASE WHEN read_at IS NOT NULL
                        THEN TIMESTAMPDIFF(SECOND, created_at, read_at) END)             AS avg_time_to_read_sec,
                    SUM(is_read = 0 AND is_archived = 0 AND is_deleted = 0)              AS unread_backlog
                 FROM notifications
                 WHERE is_deleted  = 0
                   AND channel     = 'in_app'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        $row = $this->db->fetch($sql, [$days]);
        return $row ? (array)$row : null;
    }

    public function getFunnelStats(int $days): ?array
    {
        $sql = "SELECT
                    COUNT(*)                                            AS sent,
                    SUM(is_read = 1)                                    AS opened,
                    SUM(clicked_at IS NOT NULL)                         AS clicked,
                    ROUND(AVG(is_read) * 100, 1)                        AS open_rate,
                    ROUND(SUM(clicked_at IS NOT NULL) / NULLIF(SUM(is_read), 0) * 100, 1) AS click_after_read_rate,
                    ROUND(SUM(clicked_at IS NOT NULL) / NULLIF(COUNT(*), 0) * 100, 1)     AS overall_ctr
                 FROM notifications
                 WHERE is_deleted  = 0
                   AND channel     = 'in_app'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        $row = $this->db->fetch($sql, [$days]);
        return $row ? (array)$row : null;
    }

    public function getFatigueSummary(int $threshold): ?array
    {
        $sql = "SELECT
                    COUNT(DISTINCT user_id)                                     AS affected_users,
                    AVG(unread_cnt)                                             AS avg_unread_per_user,
                    MAX(unread_cnt)                                             AS max_unread
                 FROM (
                     SELECT user_id, COUNT(*) AS unread_cnt
                     FROM notifications
                     WHERE is_read    = 0
                       AND is_deleted = 0
                       AND is_archived = 0
                       AND channel    = 'in_app'
                     GROUP BY user_id
                     HAVING unread_cnt >= ?
                 ) t";
        
        $row = $this->db->fetch($sql, [$threshold]);
        return $row ? (array)$row : null;
    }

    public function getChannelStats(int $days): array
    {
        $sql = "SELECT
                    channel,
                    COUNT(*)                                                            AS total_sent,
                    SUM(CASE WHEN channel = 'in_app' THEN is_read ELSE 0 END)          AS total_read,
                    ROUND(AVG(CASE WHEN channel = 'in_app' THEN is_read END) * 100, 1) AS read_rate
                 FROM notifications
                 WHERE is_deleted  = 0
                   AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY channel
                 ORDER BY total_sent DESC";
        
        return $this->db->fetchAll($sql, [$days]);
    }

    public function getTopEngagedUsers(int $days, int $limit): array
    {
        $sql = "SELECT
                    n.user_id,
                    u.email,
                    u.full_name,
                    COUNT(n.id)                                                     AS total_received,
                    SUM(n.is_read = 1)                                              AS total_read,
                    SUM(n.clicked_at IS NOT NULL)                                   AS total_clicked,
                    ROUND(AVG(n.is_read) * 100, 1)                                  AS read_rate
                 FROM notifications n
                 JOIN users u ON u.id = n.user_id
                 WHERE n.is_deleted  = 0
                   AND n.channel     = 'in_app'
                   AND n.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND u.deleted_at  IS NULL
                 GROUP BY n.user_id
                 HAVING total_received >= 5
                 ORDER BY read_rate DESC, total_clicked DESC
                 LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $days, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function getLeastEngagedUsers(int $days, int $limit): array
    {
        $sql = "SELECT
                    n.user_id,
                    u.email,
                    COUNT(n.id)                             AS total_received,
                    SUM(n.is_read = 1)                      AS total_read,
                    ROUND(AVG(n.is_read) * 100, 1)          AS read_rate
                 FROM notifications n
                 JOIN users u ON u.id = n.user_id
                 WHERE n.is_deleted  = 0
                   AND n.channel     = 'in_app'
                   AND n.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND u.deleted_at  IS NULL
                 GROUP BY n.user_id
                 HAVING total_received >= 5
                 ORDER BY read_rate ASC, total_received DESC
                 LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $days, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function getDailyAggregationData(string $date): array
    {
        $sql = "SELECT
                    type,
                    channel,
                    COUNT(*)                                AS sent,
                    SUM(is_read = 1)                        AS read_count,
                    SUM(clicked_at IS NOT NULL)             AS click_count,
                    COUNT(DISTINCT user_id)                 AS unique_users
                 FROM notifications
                 WHERE DATE(created_at) = ?
                   AND is_deleted       = 0
                 GROUP BY type, channel";
        
        return $this->db->fetchAll($sql, [$date]);
    }

    public function updateBatchAnalytics(string $date, object $row): bool
    {
        $sql = "INSERT INTO notification_analytics
                    (date, type, channel, sent, read_count, click_count, unique_users, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    sent         = VALUES(sent),
                    read_count   = VALUES(read_count),
                    click_count  = VALUES(click_count),
                    unique_users = VALUES(unique_users),
                    updated_at   = NOW()";
        
        return (bool)$this->db->query($sql, [
            $date,
            $row->type,
            $row->channel,
            $row->sent,
            $row->read_count,
            $row->click_count,
            $row->unique_users,
        ]);
    }

    public function getUsersByFilter(string $sql, array $params): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplateFromDb(string $key): ?object
    {
        try {
            return $this->db->table('notification_templates')
                ->where('template_key', '=', $key)
                ->first();
        } catch (\Throwable $e) {
            if (\function_exists('logger')) {
                logger()->warning('notification.templates_table_missing_or_failed', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }

    public function saveTemplateOverride(string $key, string $title, string $message): bool
    {
        $existing = $this->getTemplateFromDb($key);
        if ($existing) {
            return $this->db->table('notification_templates')
                ->where('template_key', '=', $key)
                ->update([
                    'title' => $title,
                    'message' => $message,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
        }

        return $this->db->table('notification_templates')->insert([
            'template_key' => $key,
            'title' => $title,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function deleteTemplateOverride(string $key): bool
    {
        return $this->db->table('notification_templates')
            ->where('template_key', '=', $key)
            ->delete();
    }

    public function getActiveUsersIds(): array
    {
        $rows = $this->db->table('users')
            ->select('id')
            ->whereNull('deleted_at')
            ->where('status', '=', 'active')
            ->get();

        return array_column($rows, 'id');
    }

    public function getActiveUsersIdsInBatches(int $batchSize = 200): iterable
    {
        if ($batchSize <= 0) {
            throw new \InvalidArgumentException('Batch size must be positive');
        }

        $offset = 0;
        while (true) {
            $rows = $this->db->table('users')
                ->select('id')
                ->whereNull('deleted_at')
                ->where('status', '=', 'active')
                ->limit($batchSize)
                ->offset($offset)
                ->get();

            if (empty($rows)) {
                break;
            }

            yield array_column($rows, 'id');
            $offset += $batchSize;
        }
    }

    public function getAdminUsersIds(): array
    {
        $rows = $this->db->table('users')
            ->select('id')
            ->whereNull('deleted_at')
            ->where('role', '=', 'admin')
            ->get();

        return array_column($rows, 'id');
    }

    /**
     * پردازش سگمنت کاربران به صورت تکه‌تکه (🚀 BUG-04 Fix)
     * برای جلوگیری از Memory OOM در تعداد کاربران بالا (مثلاً ۱۰۰ هزار نفر)
     */
    public function chunkUsersBySegment(string $segment, int $chunkSize, callable $callback, array $filters = []): void
    {
        $sql = "SELECT id FROM users WHERE deleted_at IS NULL";
        $params = [];

        switch ($segment) {
            case 'all':
                $sql .= " AND status = 'active'";
                break;
            case 'kyc_verified':
                $sql .= " AND status = 'active' AND kyc_status = 'approved'";
                break;
            case 'kyc_pending':
                $sql .= " AND status = 'active' AND kyc_status = 'pending'";
                break;
            case 'kyc_none':
                $sql .= " AND status = 'active' AND (kyc_status IS NULL OR kyc_status = 'none')";
                break;
            case 'level_silver':
                $sql .= " AND status = 'active' AND (level = 'silver' OR level IS NULL)";
                break;
            case 'level_gold':
                $sql .= " AND status = 'active' AND level = 'gold'";
                break;
            case 'level_vip':
                $sql .= " AND status = 'active' AND level = 'vip'";
                break;
            case 'new_users':
                $sql .= " AND status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'inactive':
                $sql .= " AND status = 'active' AND (last_login_at IS NULL OR last_login_at < DATE_SUB(NOW(), INTERVAL 60 DAY))";
                break;
            case 'custom':
                if (!empty($filters['status'])) {
                    $sql .= " AND status = ?";
                    $params[] = $filters['status'];
                }
                if (!empty($filters['kyc_status'])) {
                    $sql .= " AND kyc_status = ?";
                    $params[] = $filters['kyc_status'];
                }
                if (!empty($filters['level'])) {
                    $sql .= " AND level = ?";
                    $params[] = $filters['level'];
                }
                if (!empty($filters['registered_after'])) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $filters['registered_after'];
                }
                break;
            default:
                $sql .= " AND status = 'active'";
        }

        $offset = 0;
        while (true) {
            $chunkSql = $sql . " ORDER BY id ASC LIMIT ? OFFSET ?";
            $chunkParams = array_merge($params, [$chunkSize, $offset]);
            
            $rows = $this->db->fetchAll($chunkSql, $chunkParams);
            
            if (empty($rows)) {
                break;
            }

            $callback(array_column($rows, 'id'));
            
            if (count($rows) < $chunkSize) {
                break;
            }
            
            $offset += $chunkSize;
        }
    }
}
