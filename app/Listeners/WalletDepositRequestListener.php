<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\OutboxService;
use App\Services\Shared\IdempotencyService;
use Core\EventDispatcher;

class WalletDepositRequestListener
{
    private WalletServiceInterface $walletService;
    private LoggerInterface $logger;
    private OutboxService $outbox;
    private EventDispatcher $dispatcher;
    private IdempotencyService $idempotencyService;

    public function __construct(
        WalletServiceInterface $walletService,
        LoggerInterface $logger,
        OutboxService $outbox,
        EventDispatcher $dispatcher,
        IdempotencyService $idempotencyService
    ) {
        $this->walletService = $walletService;
        $this->logger = $logger;
        $this->outbox = $outbox;
        $this->dispatcher = $dispatcher;
        $this->idempotencyService = $idempotencyService;
    }

    public function handle($event): void
    {
        $payload = [];

        if ($event instanceof \Core\Event) {
            $payload = $event->getData();
        } elseif (is_array($event)) {
            $payload = $event;
        }

        $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : null;
        $amount = isset($payload['amount']) ? (string)$payload['amount'] : null;
        $currency = isset($payload['currency']) ? (string)$payload['currency'] : 'irt';
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];

        $retryCount = isset($metadata['retry_count']) ? (int)$metadata['retry_count'] : 0;

        if (empty($userId) || $amount === null) {
            $this->logger->warning('wallet.deposit.async.invalid_payload', [
                'payload' => $payload,
            ]);
            return;
        }

        try {
            $depositPayload = [
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => $metadata,
            ];

            $depositResult = $this->idempotencyService->executeWithTransaction(
                'wallet.deposit',
                $userId,
                $depositPayload,
                function () use ($userId, $amount, $currency, $metadata) {
                    return $this->walletService->deposit($userId, $amount, $currency, $metadata);
                },
                $metadata['idempotency_key'] ?? null
            );
            if (empty($depositResult['success'])) {
                $this->logger->warning('wallet.deposit.async.failed', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'metadata' => $metadata,
                    'result' => $depositResult,
                ]);

                if ($retryCount < 3) {
                    $retryCount++;
                    $metadata['retry_count'] = $retryCount;
                    $payload = [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'currency' => $currency,
                        'metadata' => $metadata,
                    ];
                    $this->logger->info('wallet.deposit.async.retrying', ['attempt' => $retryCount, 'user_id' => $userId]);
                    $this->dispatcher->dispatchAsync('wallet.deposit.requested', $payload);
                } else {
                    try {
                        $this->outbox->record('wallet', (string)$userId, 'wallet.deposit.requested', [
                            'user_id' => $userId,
                            'amount' => $amount,
                            'currency' => $currency,
                            'metadata' => $metadata,
                            'last_result' => $depositResult,
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->error('wallet.deposit.async.outbox_failed', ['error' => $e->getMessage()]);
                    }
                    $this->logger->error('wallet.deposit.async.dead_lettered', ['user_id' => $userId, 'amount' => $amount]);
                }
            } else {
                $this->logger->info('wallet.deposit.async.completed', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'metadata' => $metadata,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('wallet.deposit.async.exception', [
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => $metadata,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            if ($retryCount < 3) {
                $retryCount++;
                $metadata['retry_count'] = $retryCount;
                $payload = [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'metadata' => $metadata,
                ];
                $this->logger->info('wallet.deposit.async.retrying_after_exception', ['attempt' => $retryCount, 'user_id' => $userId]);
                $this->dispatcher->dispatchAsync('wallet.deposit.requested', $payload);
            } else {
                try {
                    $this->outbox->record('wallet', (string)$userId, 'wallet.deposit.requested', [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'currency' => $currency,
                        'metadata' => $metadata,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable $oe) {
                    $this->logger->error('wallet.deposit.async.outbox_failed', ['error' => $oe->getMessage()]);
                }
                $this->logger->error('wallet.deposit.async.dead_lettered_exception', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }
    }
}

