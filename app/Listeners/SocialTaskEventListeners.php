<?php

declare(strict_types=1);

namespace App\Listeners;

use Core\Event;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\IdempotencyService;
use App\Services\Gamification\TrustService;
use App\Enums\ModuleContext;
use App\Services\User\UserService;
use App\Contracts\OutboxServiceInterface;

/**
 * SocialTask Event Listeners
 */
class SocialTaskEventListeners
{
    protected LoggerInterface $logger;
    protected WalletServiceInterface $walletService;
    protected TrustService $trustService;
    protected UserService $userService;
    protected ?OutboxServiceInterface $outbox;
    protected IdempotencyService $idempotencyService;
    public function __construct(
        LoggerInterface $logger,
        WalletServiceInterface $walletService,
        TrustService $trustService,
        UserService $userService,
        ?OutboxServiceInterface $outbox = null,
        IdempotencyService $idempotencyService
    ) {        $this->logger = $logger;
        $this->walletService = $walletService;
        $this->trustService = $trustService;
        $this->userService = $userService;
        $this->outbox = $outbox;
        $this->idempotencyService = $idempotencyService;

    }

    /**
     * وقتی تسک تایید نهایی می‌شود و نیاز به پرداخت دارد
     */
    public function handleRewardApproved(Event $event): void
    {
        try {
            $data = $event->getData();
            $userId = (int)($data['user_id'] ?? $data['executor_id'] ?? 0);
            $amount = (float)($data['reward_amount'] ?? $data['amount'] ?? 0);
            $currency = (string)($data['currency'] ?? 'irt');
            $executionId = $data['execution_id'] ?? null;
            $adId = $data['ad_id'] ?? null;
            $decision = $data['decision'] ?? null;
            $riskScore = $data['risk_score'] ?? null;

            if ($userId <= 0 || $amount <= 0) {
                return;
            }

            $idemKey = "task_reward_{$executionId}";
            $payload = [
                'user_id' => $userId,
                'amount' => (string)$amount,
                'currency' => $currency,
                'metadata' => [
                    'type' => 'social_task_reward',
                    'execution_id' => $executionId,
                    'ad_id' => $adId,
                    'decision' => $decision,
                    'risk_score' => $riskScore,
                    'idempotency_key' => $idemKey,
                ],
            ];

            $pay = $this->idempotencyService->executeWithTransaction(
                'wallet.deposit',
                $userId,
                $payload,
                function () use ($userId, $amount, $currency, $payload) {
                    return $this->walletService->deposit($userId, (string)$amount, $currency, $payload['metadata']);
                },
                $idemKey
            );

            if (empty($pay['success'])) {
                throw new \RuntimeException($pay['message'] ?? 'خطا در پرداخت پاداش');
            }

            $this->logger->info('social_task.reward_paid', [
                'executor_id' => $userId,
                'execution_id' => $executionId,
                'reward_amount' => $amount,
                'currency' => $currency
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('social_task.reward_approved.failed', [
                'error' => $e->getMessage()
            ]);
            throw $e; // Throw back to orchestrator so saga fails
        }
    }

    /**
     * وقتی تسک توسط ادورتازیر رد می‌شود، کاهش تراست رخ می‌دهد
     */
    public function handleTaskRejected(Event $event): void
    {
        try {
            $data = $event->getData();
            $executorId = (int)($data['executor_id'] ?? 0);

            if ($executorId <= 0) {
                return;
            }

            $executorObj = $this->userService->findById($executorId);
            if ($executorObj) {
                $this->trustService->evaluate($executorObj, ModuleContext::SOCIAL_TASKS, 'task_rejected');
            }
        } catch (\Throwable $e) {
            $this->logger->error('social_task.task_rejected.failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * وقتی تسک لغو (Cancel) می‌شود، ردیف Outbox برای بازگشت وجه ادورتایزر نوشته می‌شود
     */
    public function handleAdCancelledRefund(Event $event): void
    {
        try {
            $data = $event->getData();
            $adId = (int)($data['ad_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            $refund = (float)($data['refund'] ?? 0);
            $currency = (string)($data['currency'] ?? 'irt');

            if ($adId <= 0 || $userId <= 0 || $refund <= 0) {
                return;
            }

            $idempotencyKey = "social_ad_cancel_refund_{$adId}";
            $payload = [
                'user_id' => $userId,
                'amount' => (string)$refund,
                'currency' => $currency,
                'metadata' => [
                    'type' => 'social_ad_refund',
                    'description' => "Refund for cancelled social ad #{$adId}",
                    'idempotency_key' => $idempotencyKey,
                    'gateway' => 'social_ad_refund',
                    'gateway_transaction_id' => 'refund_' . $adId,
                    'ref_id' => $adId,
                    'ref_type' => 'social_ad',
                ],
            ];

            if ($this->outbox) {
                $ok = $this->outbox->record('social_ad', $adId, 'wallet.deposit.requested', $payload);
                if (!$ok) {
                    throw new \RuntimeException('خطا در ثبت رکورد خروجی برای بازگشت وجه');
                }
            } else {
                $walletResult = $this->idempotencyService->executeWithTransaction(
                    'wallet.deposit',
                    $userId,
                    $payload,
                    function () use ($userId, $refund, $currency, $payload) {
                        return $this->walletService->deposit($userId, (string)$refund, $currency, $payload['metadata']);
                    },
                    $payload['metadata']['idempotency_key'] ?? null
                );
                if (empty($walletResult['success'])) {
                    throw new \RuntimeException($walletResult['message'] ?? 'خطا در بازگشت وجه');
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('social_task.ad_cancelled_refund.failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * وضعیت نهایی تسک تغییر کرده و می‌توان آن را در Outbox لاگ کرد
     */
    public function handleExecutionCompleted(Event $event): void
    {
        try {
            $data = $event->getData();
            if ($this->outbox && isset($data['execution_id'])) {
                $this->outbox->record('social_task_execution', (string)$data['execution_id'], 'social_task.execution.completed', $data);
            }
        } catch (\Throwable $e) {
            $this->logger->error('social_task.execution_completed.failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
