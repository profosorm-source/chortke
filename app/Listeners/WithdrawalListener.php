<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WithdrawalCreatedEvent;
use App\Events\WithdrawalApprovedEvent;
use App\Events\WithdrawalEvent;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Contracts\LoggerInterface;
use Core\Container;

/**
 * WithdrawalListener - Decouples withdrawal events from service layer
 * 
 * Handles:
 * - WithdrawalCreatedEvent: audit logging, notifications
 * - WithdrawalApprovedEvent: score updates, notifications
 */
class WithdrawalListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Handle withdrawal.created event
     * 
     * Logs withdrawal creation to audit trail
     * Sends notification to user
     */
    public function handleWithdrawalEvent(WithdrawalEvent $event): void
    {
        try {
            $data = $event->getData();
            $action = strtolower((string)($data['action'] ?? ''));

            switch ($action) {
                case 'created':
                    $this->processWithdrawalCreated($data);
                    break;
                case 'approved':
                    $this->processWithdrawalApproved($data);
                    break;
                case 'cancelled':
                case 'rejected':
                    $this->processWithdrawalCancelled($data);
                    break;
                case 'reversed':
                    $this->processWithdrawalReversed($data);
                    break;
                default:
                    $this->logger->warning('withdrawal event action not handled', ['action' => $action, 'event' => $data]);
                    break;
            }
        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.event listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }

    public function handleWithdrawalCreated(WithdrawalCreatedEvent $event): void
    {
        try {
            $this->processWithdrawalCreated($event->getData());
        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.created listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }

    public function handleWithdrawalApproved(WithdrawalApprovedEvent $event): void
    {
        try {
            $this->processWithdrawalApproved($event->getData());
        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.approved listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }

    private function processWithdrawalCreated(array $data): void
    {
        $userId = $data['user_id'] ?? null;
        $withdrawalId = $data['withdrawal_id'] ?? null;
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? 'irt';

        if (!$userId || !$withdrawalId) {
            $this->logger->warning('withdrawal.created event missing required data', $data);
            return;
        }

        $auditTrail = $this->container->make(AuditTrail::class);
        $auditTrail->log([
            'user_id' => $userId,
            'action' => 'withdrawal.created',
            'resource_id' => $withdrawalId,
            'metadata' => [
                'amount' => $amount,
                'currency' => $currency,
                'status' => $data['status'] ?? 'pending'
            ]
        ]);

        $notificationService = $this->container->make(NotificationService::class);
        $notificationService->send(
            $userId,
            'withdrawal.created',
            'درخواست برداشت ثبت شد',
            "درخواست برداشت #$withdrawalId با مبلغ $amount $currency ثبت شد.",
            ['withdrawal_id' => $withdrawalId]
        );
    }

    private function processWithdrawalApproved(array $data): void
    {
        $userId = $data['user_id'] ?? null;
        $withdrawalId = $data['withdrawal_id'] ?? null;
        $amount = $data['amount'] ?? 0;

        if (!$userId || !$withdrawalId) {
            $this->logger->warning('withdrawal.approved event missing required data', $data);
            return;
        }

        $scoreService = $this->container->make(\App\Services\ScoreService::class);
        $scoreService->applyDelta('user', $userId, 'score_trust', 5, 'withdrawal_approved');

        $auditTrail = $this->container->make(AuditTrail::class);
        $auditTrail->log([
            'user_id' => $userId,
            'action' => 'withdrawal.approved',
            'resource_id' => $withdrawalId,
            'metadata' => ['amount' => $amount]
        ]);

        $notificationService = $this->container->make(NotificationService::class);
        $notificationService->send(
            $userId,
            'withdrawal.approved',
            'درخواست برداشت تأیید شد',
            "درخواست برداشت #$withdrawalId تأیید شد و به زودی پردازش خواهد شد.",
            ['withdrawal_id' => $withdrawalId]
        );
    }

    private function processWithdrawalCancelled(array $data): void
    {
        $userId = $data['user_id'] ?? null;
        $withdrawalId = $data['withdrawal_id'] ?? null;
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? 'irt';

        if (!$userId || !$withdrawalId) {
            $this->logger->warning('withdrawal.cancelled event missing required data', $data);
            return;
        }

        $auditTrail = $this->container->make(AuditTrail::class);
        $auditTrail->log([
            'user_id' => $userId,
            'action' => 'withdrawal.cancelled',
            'resource_id' => $withdrawalId,
            'metadata' => [
                'amount' => $amount,
                'currency' => $currency,
                'reason' => $data['reason'] ?? null
            ]
        ]);

        $notificationService = $this->container->make(NotificationService::class);
        $notificationService->send(
            $userId,
            'withdrawal.cancelled',
            'درخواست برداشت لغو شد',
            "درخواست برداشت #$withdrawalId لغو شد و مبلغ $amount $currency بازگردانده شد.",
            ['withdrawal_id' => $withdrawalId]
        );
    }

    private function processWithdrawalReversed(array $data): void
    {
        $userId = $data['user_id'] ?? null;
        $withdrawalId = $data['withdrawal_id'] ?? null;
        $transactionId = $data['transaction_id'] ?? null;
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? 'irt';

        if (!$userId || (!$withdrawalId && !$transactionId)) {
            $this->logger->warning('withdrawal.reversed event missing required data', $data);
            return;
        }

        $auditTrail = $this->container->make(AuditTrail::class);
        $auditTrail->log([
            'user_id' => $userId,
            'action' => 'withdrawal.reversed',
            'resource_id' => $withdrawalId ?: $transactionId,
            'metadata' => [
                'amount' => $amount,
                'currency' => $currency,
                'transaction_id' => $transactionId,
                'reason' => $data['reason'] ?? null
            ]
        ]);

        $notificationService = $this->container->make(NotificationService::class);
        $notificationService->send(
            $userId,
            'withdrawal.reversed',
            'برگشت برداشت',
            "برداشت #" . ($withdrawalId ?: $transactionId) . " معکوس شد و مبلغ $amount $currency بازگردانده شد.",
            ['withdrawal_id' => $withdrawalId, 'transaction_id' => $transactionId]
        );
    }
}
