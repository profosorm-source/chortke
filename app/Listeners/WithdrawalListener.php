<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WithdrawalCreatedEvent;
use App\Events\WithdrawalApprovedEvent;
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
    public function handleWithdrawalCreated(WithdrawalCreatedEvent $event): void
    {
        try {
            $data = $event->getData();
            $userId = $data['user_id'] ?? null;
            $withdrawalId = $data['withdrawal_id'] ?? null;
            $amount = $data['amount'] ?? 0;
            $currency = $data['currency'] ?? 'irt';

            if (!$userId || !$withdrawalId) {
                $this->logger->warning('withdrawal.created event missing required data', $data);
                return;
            }

            // Log to audit trail
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

            // Send notification
            $notificationService = $this->container->make(NotificationService::class);
            $notificationService->send(
                $userId,
                'withdrawal.created',
                'درخواست برداشت ثبت شد',
                "درخواست برداشت #$withdrawalId با مبلغ $amount $currency ثبت شد.",
                ['withdrawal_id' => $withdrawalId]
            );

        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.created listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }

    /**
     * Handle withdrawal.approved event
     * 
     * Updates user score
     * Sends approval notification
     */
    public function handleWithdrawalApproved(WithdrawalApprovedEvent $event): void
    {
        try {
            $data = $event->getData();
            $userId = $data['user_id'] ?? null;
            $withdrawalId = $data['withdrawal_id'] ?? null;
            $amount = $data['amount'] ?? 0;

            if (!$userId || !$withdrawalId) {
                $this->logger->warning('withdrawal.approved event missing required data', $data);
                return;
            }

            // Update trust score
            $scoreService = $this->container->make(\App\Services\ScoreService::class);
            $scoreService->addScore($userId, 'trust', 5, 'withdrawal_approved');

            // Log to audit trail
            $auditTrail = $this->container->make(AuditTrail::class);
            $auditTrail->log([
                'user_id' => $userId,
                'action' => 'withdrawal.approved',
                'resource_id' => $withdrawalId,
                'metadata' => ['amount' => $amount]
            ]);

            // Send notification
            $notificationService = $this->container->make(NotificationService::class);
            $notificationService->send(
                $userId,
                'withdrawal.approved',
                'درخواست برداشت تأیید شد',
                "درخواست برداشت #$withdrawalId تأیید شد و به زودی پردازش خواهد شد.",
                ['withdrawal_id' => $withdrawalId]
            );

        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.approved listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }
}
