<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WalletServiceInterface;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * VitrineListingExpiryJob
 *
 * انقضای خودکار آگهی‌های ویترین:
 * - آگهی‌هایی که تاریخ انقضا گذشته، به وضعیت expired منتقل می‌شوند
 * - مبلغ Hold (در escrow) برای آگهی‌های بدون خریدار آزاد می‌شود
 * - VitrineService::releaseExpiredHolds() که در Cron ثبت نشده بود، اینجا صدا زده می‌شود
 */
class VitrineListingExpiryJob
{
    public function __construct(
        private Database $db,
        private WalletServiceInterface $walletService,
        private LoggerInterface $logger
    ) {}

    public function handle(array $data = []): void
    {
        $this->expireListings();
        $this->releaseHoldsForExpired();
    }

    /**
     * انتقال آگهی‌های منقضی شده به وضعیت expired
     */
    private function expireListings(): void
    {
        try {
            $affected = (int) $this->db->execute(
                "UPDATE vitrine_listings
                 SET status = 'expired', updated_at = NOW()
                 WHERE status = 'active'
                   AND expires_at IS NOT NULL
                   AND expires_at < NOW()"
            );

            if ($affected > 0) {
                $this->logger->info('vitrine.listings_expired', ['count' => $affected]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.expire_listings_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * آزادسازی Hold مبالغ آگهی‌های بدون خریدار که منقضی شده‌اند
     * (جایگزین VitrineService::releaseExpiredHolds() که در Cron صدا زده نمی‌شد)
     */
    private function releaseHoldsForExpired(): void
    {
        try {
            // آگهی‌هایی که expire شده‌اند اما hold آنها هنوز آزاد نشده
            $listings = $this->db->fetchAll(
                "SELECT vl.id, vl.user_id, vl.hold_amount, vl.currency
                 FROM vitrine_listings vl
                 WHERE vl.status = 'expired'
                   AND vl.hold_released = 0
                   AND vl.hold_amount > 0
                 LIMIT 200"
            );

            $released = 0;
            foreach ($listings as $listing) {
                try {
                    $this->db->beginTransaction();

                    // آزادسازی مبلغ Hold از escrow به کیف پول کاربر
                    $this->walletService->deposit(
                        (int) $listing->user_id,
                        (string) $listing->hold_amount,
                        (string) ($listing->currency ?: 'irt'),
                        [
                            'type'        => 'vitrine_hold_release',
                            'listing_id'  => $listing->id,
                            'description' => 'آزادسازی Hold آگهی ویترین منقضی‌شده',
                        ]
                    );

                    $this->db->prepare(
                        "UPDATE vitrine_listings SET hold_released = 1, hold_released_at = NOW() WHERE id = ?"
                    )->execute([$listing->id]);

                    $this->db->commit();
                    $released++;
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->logger->error('vitrine.hold_release_failed', [
                        'listing_id' => $listing->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            if ($released > 0) {
                $this->logger->info('vitrine.holds_released', ['count' => $released]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.release_holds_job_failed', ['error' => $e->getMessage()]);
        }
    }
}
