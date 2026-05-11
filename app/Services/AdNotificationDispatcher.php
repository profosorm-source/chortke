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

            // ✅ OPTIMIZATION: Single, super-lean query to get all tokens needed at once
            $maxOffset = 0;
            foreach ($activeAds as $ad) {
                $offset = (int)($ad->impressions ?? 0);
                if ($offset > $maxOffset) {
                    $maxOffset = $offset;
                }
            }

            // Fetch linear list of active FCM tokens with safety limit
            $tokenRows = $this->db->fetchAll(
                "SELECT fcm_token FROM user_devices WHERE fcm_token IS NOT NULL AND LENGTH(fcm_token) > 10 LIMIT ?",
                [$maxOffset + 1000]
            );

            if (empty($tokenRows)) {
                return $stats; // No targetable devices exist in system
            }

            $allTokens = array_column($tokenRows, 'fcm_token');

            // ✅ Prepare batch updates for ads
            $adsUpdates = [];

            foreach ($activeAds as $ad) {
                $payload = json_decode($ad->restrictions ?? '', true) ?: [];
                
                // 2. Get continuous transmission window for this ad
                $offset = (int) ($ad->impressions ?? 0);
                $limit = 100;
                
                // Slice tokens directly from linear mapping
                $tokensSlice = array_slice($allTokens, $offset, $limit);

                if (empty($tokensSlice)) {
                    // Exhausted all active system devices, mark completed.
                    $adsUpdates[$ad->id] = ['status' => 'completed', 'impressions' => $ad->impressions];
                    continue;
                }

                // 3. Physically transmit via existing bridge
                $this->fcmService->sendToTokens(
                    $tokensSlice,
                    $ad->title,
                    $payload['push_body'] ?? 'برای مشاهده کلیک کنید',
                    ['ad_id' => $ad->id],
                    $payload['image_path'] ?? null,
                    $ad->link ?? '#'
                );

                $sentSuccessfully = count($tokensSlice);
                
                // 4. Queue impression and budget update
                $adsUpdates[$ad->id] = [
                    'impressions' => (int)$ad->impressions + $sentSuccessfully,
                    // If site takes cost per push, implement mathematical deduction logic here
                ];

                $stats['ads_processed']++;
                $stats['total_sent'] += $sentSuccessfully;
                
                $this->logInfo('ad_push_delivered', ['ad_id' => $ad->id, 'count' => $sentSuccessfully]);
            }

            // ✅ EFFICIENT STATE SAVING: Fire one single multi-record CASE statement
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
