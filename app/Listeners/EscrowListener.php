<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EscrowReleasedEvent;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\IdempotencyService;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Contracts\LoggerInterface;
use Core\Container;

/**
 * EscrowListener - Handles escrow release events
 * 
 * Decouples escrow management from:
 * - Wallet operations
 * - User notifications
 * - Audit logging
 * - Ledger updates
 */
class EscrowListener
{
    private Container $container;
    private LoggerInterface $logger;
    private IdempotencyService $idempotencyService;

    public function __construct(Container $container, LoggerInterface $logger, IdempotencyService $idempotencyService)
    {
        $this->container = $container;
        $this->logger = $logger;
        $this->idempotencyService = $idempotencyService;
    }

    /**
     * Handle escrow.released event
     * 
     * Credits wallet
     * Updates ledger
     * Sends notification
     */
    public function handle(EscrowReleasedEvent $event): void
    {
        try {
            $data = $event->getData();
            $escrowId = $data['escrow_id'] ?? null;
            $recipientId = $data['recipient_id'] ?? null;
            $amount = $data['amount'] ?? 0;
            $currency = $data['currency'] ?? 'irt';

            if (!$escrowId || !$recipientId) {
                $this->logger->warning('escrow.released event missing required data', $data);
                return;
            }

            // Credit wallet (prefer async Outbox)
            $walletService = $this->container->make(WalletServiceInterface::class);
            $outbox = null;
            try {
                $outbox = $this->container->make(\App\Services\OutboxService::class);
            } catch (\Throwable $e) {
                // no outbox available
            }

            $idemKey = 'escrow_rel:' . $escrowId;

            if ($outbox) {
                $payload = [
                    'user_id' => (int)$recipientId,
                    'amount' => (string)$amount,
                    'currency' => $currency,
                    'metadata' => [
                        'type' => 'escrow_release',
                        'escrow_id' => $escrowId,
                        'idempotency_key' => $idemKey,
                    ],
                ];
                $ok = $outbox->record('escrow', $escrowId, 'wallet.deposit.requested', $payload);
                if (!$ok) {
                    $this->logger->error('escrow.outbox_record_failed', ['escrow_id' => $escrowId]);
                    return;
                }
                $result = ['transaction_id' => null];
            } else {
                $payload = [
                    'user_id' => (int)$recipientId,
                    'amount' => (string)$amount,
                    'currency' => $currency,
                    'metadata' => [
                        'type' => 'escrow_release',
                        'escrow_id' => $escrowId,
                    ],
                ];

                $depositResult = $this->idempotencyService->executeWithTransaction(
                    'wallet.deposit',
                    (int)$recipientId,
                    $payload,
                    function () use ($walletService, $recipientId, $amount, $currency, $payload) {
                        return $walletService->deposit($recipientId, $amount, $currency, $payload['metadata']);
                    },
                    $idemKey
                );

                if (empty($depositResult['success'])) {
                    $this->logger->error('Failed to deposit escrow release to wallet', [
                        'escrow_id' => $escrowId,
                        'recipient_id' => $recipientId,
                        'amount' => $amount,
                        'result' => $depositResult,
                    ]);
                    return;
                }

                $result = $depositResult;
            }

            // Log to audit trail
            $auditTrail = $this->container->make(AuditTrail::class);
            $auditTrail->log([
                'user_id' => $recipientId,
                'action' => 'escrow.released',
                'resource_id' => $escrowId,
                'metadata' => [
                    'amount' => $amount,
                    'currency' => $currency,
                    'wallet_transaction_id' => $result['transaction_id'] ?? null
                ]
            ]);

            // Send notification
            $notificationService = $this->container->make(NotificationService::class);
            $notificationService->send(
                $recipientId,
                'escrow.released',
                'وجه کسری آزاد شد',
                "مبلغ $amount $currency از escrow #$escrowId به حساب شما منتقل شد.",
                [
                    'escrow_id' => $escrowId,
                    'amount' => $amount,
                    'transaction_id' => $result['transaction_id'] ?? null
                ]
            );

        } catch (\Throwable $e) {
            $this->logger->error('escrow.released listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }
}
