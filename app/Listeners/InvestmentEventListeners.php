<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\LoggerInterface;
use Core\Container;
use App\Services\Shared\IdempotencyService;

/**
 * InvestmentEventListeners - Centralized event handling for investment domain
 * 
 * Decouples InvestmentService from WalletServiceInterface, NotificationService, UserService, and ReferralService
 * by replacing direct method calls with event-driven handlers.
 * 
 * Dependencies are lazy-loaded via Container to avoid constructor bloat.
 */
class InvestmentEventListeners
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
     * Handle investment.created event
     * 
     * Triggers:
     * - Referral commission processing
     * - Wallet cache invalidation
     * - User notification
     */
    public function handleInvestmentCreated($event)
    {
        try {
            if ($event instanceof \Core\Event) {
                $data = $event->getData();
            } elseif (is_array($event)) {
                $data = $event['data'] ?? $event;
            } else {
                $data = [];
            }
            $userId = $data['user_id'] ?? null;
            $amount = $data['amount'] ?? 0;
            $investmentId = $data['investment_id'] ?? null;

            if (!$userId || !$investmentId) {
                $this->logger->warning('investment.created event missing user_id or investment_id', $data);
                return;
            }

            $this->processReferralCommission($userId, $amount, 'investment_creation', [
                'investment_id' => $investmentId,
            ]);

            $this->invalidateWalletCache($userId);

            $this->notifyUser($userId, 'سرمایه‌گذاری ثبت شد', "سرمایه‌گذاری شما با موفقیت ثبت شد.");

        } catch (\Throwable $e) {
            $this->logger->error('investment.created handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle investment.profit_applied event
     * 
     * Triggers:
     * - Wallet balance update notification
     * - User notification with profit/loss details
     */
    public function handleInvestmentProfitApplied($event)
    {
        try {
            if ($event instanceof \Core\Event) {
                $data = $event->getData();
            } elseif (is_array($event)) {
                $data = $event['data'] ?? $event;
            } else {
                $data = [];
            }
            $userId = $data['user_id'] ?? null;
            $investmentId = $data['investment_id'] ?? null;
            $netAmount = $data['net_amount'] ?? 0;
            $balanceAfter = $data['balance_after'] ?? 0;
            $period = $data['period'] ?? null;
            $profitType = $data['profit_type'] ?? 'profit';

            if (!$userId || !$investmentId) {
                $this->logger->warning('investment.profit_applied event missing user_id or investment_id', $data);
                return;
            }

            $this->invalidateWalletCache($userId);

            $typeLabel = $profitType === 'profit' ? 'سود' : 'ضرر';
            $currencyService = $this->container->make('App\Services\CurrencyService');
            $amountFormatted = $currencyService->formatAmount(abs($netAmount), 'usdt');
            $balanceFormatted = $currencyService->formatAmount($balanceAfter, 'usdt');

            $this->notifyUser(
                $userId,
                "گزارش سرمایه‌گذاری: {$typeLabel}",
                "دوره {$period}: {$typeLabel} {$amountFormatted} | موجودی جدید: {$balanceFormatted}"
            );

        } catch (\Throwable $e) {
            $this->logger->error('investment.profit_applied handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle investment.withdrawn event
     * 
     * Triggers:
     * - Wallet deposit for withdrawn amount
     * - User notification
     */
    public function handleInvestmentWithdrawn($event)
    {
        try {
            if ($event instanceof \Core\Event) {
                $data = $event->getData();
            } elseif (is_array($event)) {
                $data = $event['data'] ?? $event;
            } else {
                $data = [];
            }
            $userId = $data['user_id'] ?? null;
            $amount = $data['amount'] ?? 0;
            $investmentId = $data['investment_id'] ?? null;

            if (!$userId || !$investmentId) {
                $this->logger->warning('investment.withdrawn event missing user_id or investment_id', $data);
                return;
            }

            // Deposit to wallet (prefer async Outbox when available)
            $walletService = $this->container->make('App\Contracts\WalletServiceInterface');
            $outbox = null;
            try {
                $outbox = $this->container->make(\App\Services\OutboxService::class);
            } catch (\Throwable $e) {
                // no outbox available, will fallback
            }

            $payload = [
                'user_id' => (int)$userId,
                'amount' => (string)$amount,
                'currency' => 'usdt',
                'metadata' => [
                    'type' => 'investment_withdrawal',
                    'description' => 'برداشت سرمایه‌گذاری',
                    'investment_id' => $investmentId,
                    'idempotency_key' => 'investment_withdrawal:' . $investmentId,
                ],
            ];

            if ($outbox) {
                $ok = $outbox->record('investment', $investmentId, 'wallet.deposit.requested', $payload);
                if (empty($ok)) {
                    $this->logger->error('investment.withdrawn outbox_record_failed', [
                        'investment_id' => $investmentId,
                        'user_id' => $userId,
                        'amount' => $amount
                    ]);
                    return;
                }
            } else {
                $depositResult = $this->idempotencyService->executeWithTransaction(
                    'wallet.deposit',
                    $userId,
                    $payload,
                    function () use ($walletService, $userId, $amount, $payload) {
                        return $walletService->deposit($userId, (string)$amount, 'usdt', $payload['metadata']);
                    },
                    $payload['metadata']['idempotency_key']
                );

                if (empty($depositResult['success'])) {
                    $this->logger->error('investment.withdrawn deposit failed', [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'error' => $depositResult['message'] ?? 'Unknown error',
                    ]);
                    return;
                }
            }

            $this->invalidateWalletCache($userId);

            $currencyService = $this->container->make('App\Services\CurrencyService');
            $amountFormatted = $currencyService->formatAmount($amount, 'usdt');

            $this->notifyUser(
                $userId,
                'برداشت سرمایه‌گذاری',
                "مبلغ {$amountFormatted} برای برداشت سرمایه‌گذاری به کیف‌پول شما منتقل شد."
            );

        } catch (\Throwable $e) {
            $this->logger->error('investment.withdrawn handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle investment.completed event
     * 
     * Triggers:
     * - Final balance notification
     * - User notification
     */
    public function handleInvestmentCompleted($event)
    {
        try {
            if ($event instanceof \Core\Event) {
                $data = $event->getData();
            } elseif (is_array($event)) {
                $data = $event['data'] ?? $event;
            } else {
                $data = [];
            }
            $userId = $data['user_id'] ?? null;
            $finalBalance = $data['final_balance'] ?? 0;
            $investmentId = $data['investment_id'] ?? null;

            if (!$userId || !$investmentId) {
                $this->logger->warning('investment.completed event missing user_id or investment_id', $data);
                return;
            }

            $this->invalidateWalletCache($userId);

            $currencyService = $this->container->make('App\Services\CurrencyService');
            $balanceFormatted = $currencyService->formatAmount($finalBalance, 'usdt');

            $this->notifyUser(
                $userId,
                'سرمایه‌گذاری به پایان رسید',
                "سرمایه‌گذاری شما به پایان رسید. موجودی نهایی: {$balanceFormatted}"
            );

        } catch (\Throwable $e) {
            $this->logger->error('investment.completed handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle investment.frozen event
     * 
     * Triggers:
     * - User notification about frozen investment
     */
    public function handleInvestmentFrozen($event)
    {
        try {
            if ($event instanceof \Core\Event) {
                $data = $event->getData();
            } elseif (is_array($event)) {
                $data = $event['data'] ?? $event;
            } else {
                $data = [];
            }
            $userId = $data['user_id'] ?? null;
            $reason = $data['reason'] ?? 'Unknown';
            $investmentId = $data['investment_id'] ?? null;

            if (!$userId || !$investmentId) {
                $this->logger->warning('investment.frozen event missing user_id or investment_id', $data);
                return;
            }

            $this->invalidateWalletCache($userId);

            $this->notifyUser(
                $userId,
                'سرمایه‌گذاری متوقف شد',
                "سرمایه‌گذاری شما به دلیل {$reason} متوقف شد. لطفاً با پشتیبانی تماس بگیرید."
            );

        } catch (\Throwable $e) {
            $this->logger->error('investment.frozen handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    // ========== HELPER METHODS ==========

    /**
     * Process referral commission for investment actions
     */
    private function processReferralCommission(int $userId, float $amount, string $action, array $metadata = []): void
    {
        try {
            $userService = $this->container->make('App\Services\User\UserService');
            $userRecord = $userService->findById($userId);

            if (!$userRecord || empty($userRecord->referred_by)) {
                return;
            }

            $referralService = $this->container->make('App\Services\Shared\ReferralService');
            $referralService->processCommission((int)$userRecord->referred_by, $amount, 'usdt', [
                'action' => $action,
                'investor_id' => $userId,
                ...$metadata,
            ]);

        } catch (\Throwable $e) {
            $this->logger->warning('referral commission processing failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate wallet cache for a user
     */
    private function invalidateWalletCache(int $userId): void
    {
        try {
            $cacheService = $this->container->make('App\Services\Cache\CacheInvalidationService');
            $cacheService->invalidateWallet($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('wallet cache invalidation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification to user
     */
    private function notifyUser(int $userId, string $title, string $message, string $type = 'investment_update'): void
    {
        try {
            $notificationService = $this->container->make('App\Contracts\NotificationServiceInterface');
            $notificationService->send($userId, $type, $title, $message);
        } catch (\Throwable $e) {
            $this->logger->warning('notification sending failed', [
                'user_id' => $userId,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
