<?php

namespace App\Services;

use Core\Database;
use App\Services\Notification\NotificationService;
use App\Contracts\LoggerInterface;
use App\Contracts\CacheInterface;

/**
 * AdNotificationDispatcher - Responsible for transmitting ad notification campaigns in the background.
 */
class AdNotificationDispatcher
{
    private \App\Contracts\LoggerInterface $logger;
    private \Core\Cache $cache;
    private \Core\Database $db;
    private NotificationService $notificationService;
    private PerformanceOptimizationService $performanceService;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \Core\Cache $cache,
        \Core\Database $db,
        NotificationService $notificationService,
        PerformanceOptimizationService $performanceService
    ) {        $this->logger = $logger;
        $this->cache = $cache;
        $this->db = $db;
        $this->notificationService = $notificationService;
        $this->performanceService = $performanceService;

        
    }

    /**
     * Scans pending active notification campaigns and delivers them in parallel batches.
     */
    public function processAdNotifications(): array
    {
        $stats = ['ads_processed' => 0, 'total_sent' => 0];
        
        // 🚀 BUG-09 Fix: Distributed Lock to prevent overlapping runs
        $lockKey = 'lock:ad_notification_process';
        
        if (!$this->cache->set($lockKey, '1', 300)) {
            $this->logger->info('ad_process_locked', 'Another ad process is already running.');
            return $stats;
        }

        try {
            // 1. Find active notification ads that are approved and not yet completed
            $activeAds = $this->db->fetchAll(
                "SELECT id, title, type, status, remaining_budget, impressions, restrictions, link FROM ads WHERE type = 'notification' AND status = 'active' AND remaining_budget > 0 LIMIT 5"
            );

            if (empty($activeAds)) {
                $this->cache->delete($lockKey);
                return $stats;
            }

            $adsUpdates = [];
            $notificationQueue = []; // OUTBOX PATTERN: Store notifications to send after transaction

            foreach ($activeAds as $ad) {
                $restrictions = json_decode($ad->restrictions ?? '', true) ?: [];
                
                // MED-03: بسازید query برای دریافت کاربران با توجه به targeting restrictions
                $where = ["ud.fcm_token IS NOT NULL", "LENGTH(ud.fcm_token) > 10", "u.status = 'active'"];
                $params = [];
                
                // اعمال محدودیت‌های تبلیغ
                if (!empty($restrictions['age_min'])) {
                    // M27 Fix: محاسبه دقیق سن بر حسب تقویم روزانه به جای محاسبه خام اختلاف سال
                    $where[] = "TIMESTAMPDIFF(YEAR, u.birth_date, CURDATE()) >= ?";
                    $params[] = $restrictions['age_min'];
                }
                if (!empty($restrictions['age_max'])) {
                    $where[] = "TIMESTAMPDIFF(YEAR, u.birth_date, CURDATE()) <= ?";
                    $params[] = $restrictions['age_max'];
                }
                if (!empty($restrictions['regions']) && is_array($restrictions['regions'])) {
                    $validRegions = array_values(array_filter($restrictions['regions'], fn($r) => is_string($r) || is_numeric($r)));
                    if (!empty($validRegions)) {
                        $regionPlaceholders = array_fill(0, count($validRegions), '?');
                        $where[] = "u.region IN (" . implode(',', $regionPlaceholders) . ")";
                        $params = array_merge($params, array_map('strval', $validRegions));
                    }
                }
                $allowedGenders = ['male', 'female', 'other'];
                if (!empty($restrictions['gender']) && in_array($restrictions['gender'], $allowedGenders, true)) {
                    $where[] = "u.gender = ?";
                    $params[] = $restrictions['gender'];
                }
                
                $whereClause = implode(' AND ', $where);
                
                // دریافت شناسه کاربران با offset (برای pagination تبلیغ)
                $offset = (int) ($ad->impressions ?? 0);
                $limit = 100;
                
                $userQuery = "SELECT DISTINCT u.id FROM users u
                             JOIN user_devices ud ON u.id = ud.user_id
                             WHERE {$whereClause}
                             ORDER BY ud.created_at DESC
                             LIMIT ? OFFSET ?";
                
                $params[] = $limit;
                $params[] = $offset;
                
                $userRows = $this->db->fetchAll($userQuery, $params);

                if (empty($userRows)) {
                    // هیچ کاربری مطابق شرایط پیدا نشد یا تمام تبلیغات ارسال شد
                    $adsUpdates[$ad->id] = ['status' => 'completed', 'impressions' => $ad->impressions];
                    continue;
                }

                $userIds = array_map('intval', array_column($userRows, 'id'));

                // OUTBOX: Queue notifications instead of sending directly
                // This ensures notifications are only sent AFTER ads table is successfully updated
                $notificationQueue[] = [
                    'ad_id' => (int)$ad->id,
                    'user_ids' => $userIds,
                    'title' => $ad->title,
                    'body' => $restrictions['push_body'] ?? 'برای مشاهده کلیک کنید',
                    'link' => $ad->link ?? '#'
                ];
                
                // 4. بروزرسانی impression و budget (count will be updated after notifications sent successfully)
                $adsUpdates[$ad->id] = [
                    'impressions' => (int)$ad->impressions + count($userIds), // Pre-calculate
                ];

                $stats['ads_processed']++;
                $stats['total_sent'] += count($userIds);
                
                $this->logger->info('ad_push_queued', ['ad_id' => $ad->id, 'count' => count($userIds)]);
            }

            // ✅ TRANSACTION: Update ads in DB atomically
            if (!empty($adsUpdates)) {
                $this->db->beginTransaction();
                try {
                    $this->performanceService->bulkUpdateWithCase(
                        'ads',
                        'id',
                        $adsUpdates
                    );
                    $this->db->commit();
                } catch (\Throwable $txnError) {
                    $this->db->rollBack();
                    $this->logger->error('ad_state_update_failed', $txnError->getMessage());
                    throw $txnError;
                }
            }

            // ✅ OUTBOX PATTERN: Send notifications AFTER transaction committed
            // If notification fails, it's async and won't rollback the DB transaction
            foreach ($notificationQueue as $notification) {
                try {
                    $sentCount = $this->notificationService->sendBulk(
                        $notification['user_ids'],
                        'marketing',
                        $notification['title'],
                        $notification['body'],
                        ['ad_id' => $notification['ad_id']],
                        $notification['link']
                    );
                    $this->logger->info('ad_push_delivered', ['ad_id' => $notification['ad_id'], 'count' => $sentCount]);
                } catch (\Throwable $notifError) {
                    // Log but don't fail - notifications are best-effort
                    $this->logger->error('ad_notification_failed', ['ad_id' => $notification['ad_id'], 'error' => $notifError->getMessage()]);
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error('ad_push_cron_fail', $e->getMessage());
        } finally {
            $this->cache->delete($lockKey);
        }

        return $stats;
    }
}
