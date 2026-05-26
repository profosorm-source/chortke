<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\LoggerInterface;
use App\Services\Shared\ReferralService;
use Core\Container;

/**
 * ReferralCommissionListener
 * 
 * Handles referral commission processing for various reward events
 * (tasks, sales, vip purchases, etc.)
 */
class ReferralCommissionListener
{
    private ReferralService $referralService;
    private LoggerInterface $logger;

    public function __construct(
        ReferralService $referralService,
        LoggerInterface $logger
    ) {
        $this->referralService = $referralService;
        $this->logger = $logger;
    }

    /**
     * Handle referral commission event
     * 
     * Expected payload format:
     * - referrer_id: int (ID of the referrer to credit)
     * - amount: float|string (Commission amount)
     * - currency: string (Currency code: 'usdt', 'content_approval', etc.)
     * - context: array (Additional data like action, executor_id, etc.)
     * - source_user_id: int (The user whose action triggered the commission)
     */
    public function handle($event): void
    {
        try {
            $payload = $this->extractPayload($event);
            
            if (empty($payload['referrer_id'])) {
                $this->logger->warning('referral.commission.missing_referrer', $payload);
                return;
            }

            $result = $this->referralService->processCommission(
                (int)$payload['referrer_id'],
                (string)$payload['amount'],
                (string)$payload['currency'],
                $payload['context'] ?? []
            );

            if (!$result['success'] ?? false) {
                $this->logger->warning('referral.commission.process_failed', [
                    'referrer_id' => $payload['referrer_id'],
                    'amount' => $payload['amount'],
                    'result' => $result
                ]);
            } else {
                $this->logger->info('referral.commission.processed', [
                    'referrer_id' => $payload['referrer_id'],
                    'amount' => $payload['amount'],
                    'currency' => $payload['currency'],
                    'source_user_id' => $payload['source_user_id'] ?? null
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('referral.commission.exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'event' => $event
            ]);
        }
    }

    /**
     * Extract payload from various event formats
     */
    private function extractPayload($event): array
    {
        if (is_array($event)) {
            return $event;
        }

        if ($event instanceof \Core\Event) {
            return $event->getData();
        }

        if (is_object($event) && method_exists($event, 'getData')) {
            return $event->getData();
        }

        return [];
    }
}
