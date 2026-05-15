<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Services\Notification\FcmService;
use App\Contracts\LoggerInterface;

/**
 * AdNotificationDispatcher - Responsible for transmitting ad notification campaigns in the background.
 */
class AdNotificationDispatcher extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        private FcmService $fcmService,
        private PerformanceOptimizationService $performanceService,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * Scans pending active notification campaigns and delivers them in parallel batches.
     */
    public function processAdNotifications(): array
    {
        $stats = ['ads_processed' => 0, 'total_sent' => 0];
        
        // 🚀 BUG-09 Fix: Distributed Lock to prevent overlapping runs
        $lockKey = 'lock:ad_notification_process';
        $redis = $this->performanceService->redis();
        
        if ($redis && !$redis->set($lockKey, '1', ['nx', 'ex' => 300])) {
            $this->logInfo('ad_process_locked', 'Another ad process is already running.');
            return $stats;
        }

        try {
            // 1. Find active notification ads that are approved and not yet completed
            $activeAds = $this->db->fetchAll(
                "SELECT id, title, type, status, remaining_budget, impressions, restrictions, link FROM ads WHERE type = 'notification' AND status = 'active' AND remaining_budget > 0 LIMIT 5"
            );

            if (empty($activeAds)) {
                if ($redis) $redis->del($lockKey);
                return $stats;
            }

            $adsUpdates = [];

            foreach ($activeAds as $ad) {
                $restrictions = json_decode($ad->restrictions ?? '', true) ?: [];
                
                // MED-03: بسازید query برای دریافت tokens با توجه به targeting restrictions
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
                
                // دریافت tokens با offset (برای pagination تبلیغ)
                $offset = (int) ($ad->impressions ?? 0);
                $limit = 100;
                
                $tokenQuery = "SELECT ud.fcm_token FROM user_devices ud
                             JOIN users u ON u.id = ud.user_id
                             WHERE {$whereClause}
                             ORDER BY ud.created_at DESC
                             LIMIT ? OFFSET ?";
                
                $params[] = $limit;
                $params[] = $offset;
                
                $tokenRows = $this->db->fetchAll($tokenQuery, $params);

                if (empty($tokenRows)) {
                    // هیچ دستگاه مطابق شرایط پیدا نشد یا تمام tokenها ارسال شد
                    $adsUpdates[$ad->id] = ['status' => 'completed', 'impressions' => $ad->impressions];
                    continue;
                }

                $tokensSlice = array_column($tokenRows, 'fcm_token');

                // 3. ارسال از طریق FCM
                // MED-04 Fix: دریافت خروجی واقعی FCM جهت سنجش دقیق دیتای Impressions و بودجه
                $result = $this->fcmService->sendToTokens(
                    $tokensSlice,
                    $ad->title,
                    $restrictions['push_body'] ?? 'برای مشاهده کلیک کنید',
                    ['ad_id' => $ad->id],
                    $restrictions['image_path'] ?? null,
                    $ad->link ?? '#'
                );

                if (!isset($result['success']) || !$result['success']) {
                    $this->logWarning('fcm_send_failed', ['ad_id' => $ad->id]);
                    continue;
                }

                // L-SRV-01 Fix: در صورت نامشخص بودن مقدار خروجی FCM، مقدار پیش‌فرض را 0 قرار دهید (نه کل قطاع توکن‌ها) جهت ممانعت از تخریب و تورم دیتای Impression
                $sentSuccessfully = $result['sent'] ?? 0;
                
                // 4. بروزرسانی impression و budget
                $adsUpdates[$ad->id] = [
                    'impressions' => (int)$ad->impressions + $sentSuccessfully,
                ];

                $stats['ads_processed']++;
                $stats['total_sent'] += $sentSuccessfully;
                
                $this->logInfo('ad_push_delivered', ['ad_id' => $ad->id, 'count' => $sentSuccessfully]);
            }

            // ✅ EFFICIENT STATE SAVING
            if (!empty($adsUpdates)) {
                $this->performanceService->bulkUpdateWithCase(
                    'ads',
                    'id',
                    $adsUpdates
                );
            }

        } catch (\Throwable $e) {
            $this->logError('ad_push_cron_fail', $e->getMessage());
        } finally {
            if ($redis) $redis->del($lockKey);
        }

        return $stats;
    }
}
