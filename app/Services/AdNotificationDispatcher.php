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

        try {
            // 1. Find active notification ads that are approved and not yet completed
            $activeAds = $this->db->fetchAll(
                "SELECT * FROM ads WHERE type = 'notification' AND status = 'active' AND remaining_budget > 0 LIMIT 5"
            );

            if (empty($activeAds)) {
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
                    $where[] = "YEAR(CURDATE()) - YEAR(u.birth_date) >= ?";
                    $params[] = $restrictions['age_min'];
                }
                if (!empty($restrictions['age_max'])) {
                    $where[] = "YEAR(CURDATE()) - YEAR(u.birth_date) <= ?";
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
                if (!empty($restrictions['gender']) && is_string($restrictions['gender'])) {
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
                $this->fcmService->sendToTokens(
                    $tokensSlice,
                    $ad->title,
                    $restrictions['push_body'] ?? 'برای مشاهده کلیک کنید',
                    ['ad_id' => $ad->id],
                    $restrictions['image_path'] ?? null,
                    $ad->link ?? '#'
                );

                $sentSuccessfully = count($tokensSlice);
                
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
        }

        return $stats;
    }
}
