<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\KYCApprovedEvent;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Contracts\LoggerInterface;
use Core\Container;
use Core\Database;

/**
 * KYCListener - Handles KYC approval events
 * 
 * Decouples KYC verification from:
 * - Feature unlocks
 * - Withdrawal limits increase
 * - User notifications
 * - Audit logging
 */
class KYCListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Handle kyc.approved event
     * 
     * Unlocks premium features
     * Increases withdrawal limits
     * Updates user status
     * Sends notification
     */
    public function handle(KYCApprovedEvent $event): void
    {
        try {
            $data = $event->getData();
            $userId = $data['user_id'] ?? null;
            $kycLevel = $data['kyc_level'] ?? 1;

            if (!$userId) {
                $this->logger->warning('kyc.approved event missing user_id', $data);
                return;
            }

            $db = $this->container->make(Database::class);

            // Update user KYC status
            $db->query(
                'UPDATE users SET kyc_level = ?, kyc_verified_at = NOW() WHERE id = ?',
                [$kycLevel, $userId]
            );

            // Unlock features based on KYC level
            $this->unlockFeatures($userId, $kycLevel);

            // Update withdrawal limits
            $this->updateWithdrawalLimits($userId, $kycLevel);

            // Log to audit trail
            $auditTrail = $this->container->make(AuditTrail::class);
            $auditTrail->log([
                'user_id' => $userId,
                'action' => 'kyc.approved',
                'metadata' => [
                    'kyc_level' => $kycLevel
                ]
            ]);

            // Send notification
            $notificationService = $this->container->make(NotificationService::class);
            $notificationService->send(
                $userId,
                'kyc.approved',
                '✓ تایید هویت کامل شد',
                'سطح تایید هویت شما به سطح ' . $kycLevel . ' ارتقا یافت. ویژگی‌های بیشتری فعال شد.',
                ['kyc_level' => $kycLevel]
            );

        } catch (\Throwable $e) {
            $this->logger->error('kyc.approved listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }

    /**
     * Unlock features based on KYC level
     */
    private function unlockFeatures(int $userId, int $kycLevel): void
    {
        try {
            $db = $this->container->make(Database::class);

            $features = [];
            
            if ($kycLevel >= 1) {
                $features[] = 'basic_withdrawal';
                $features[] = 'deposit_crypto';
            }
            if ($kycLevel >= 2) {
                $features[] = 'manual_deposit';
                $features[] = 'investment';
            }
            if ($kycLevel >= 3) {
                $features[] = 'high_withdrawal_limit';
                $features[] = 'escrow_services';
            }

            foreach ($features as $feature) {
                $db->query(
                    'INSERT IGNORE INTO user_features (user_id, feature_key, enabled_at) 
                     VALUES (?, ?, NOW())',
                    [$userId, $feature]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to unlock features', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update withdrawal limits based on KYC level
     */
    private function updateWithdrawalLimits(int $userId, int $kycLevel): void
    {
        try {
            $db = $this->container->make(Database::class);

            $dailyLimit = match($kycLevel) {
                1 => 5_000_000,      // 5M irt / 50 usdt
                2 => 25_000_000,     // 25M irt / 250 usdt
                3 => 100_000_000,    // 100M irt / 1000 usdt
                default => 0
            };

            $monthlyLimit = $dailyLimit * 30;

            $db->query(
                'UPDATE user_settings SET 
                    daily_withdrawal_limit = ?,
                    monthly_withdrawal_limit = ?
                 WHERE user_id = ?',
                [$dailyLimit, $monthlyLimit, $userId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to update withdrawal limits', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
