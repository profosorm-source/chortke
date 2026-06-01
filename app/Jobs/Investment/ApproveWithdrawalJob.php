<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

class ApproveWithdrawalJob
{
    private \Core\Database $db;
    private ?\App\Contracts\OutboxServiceInterface $outboxService;
    private \App\Contracts\WalletServiceInterface $walletService;
    private \App\Models\InvestmentWithdrawal $withdrawalModel;
    private \App\Services\StateMachineService $stateMachine;
    private \App\Models\Investment $investmentModel;
    private \Core\EventDispatcher $eventDispatcher;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null,
        \App\Contracts\WalletServiceInterface $walletService,
        \App\Models\InvestmentWithdrawal $withdrawalModel,
        \App\Services\StateMachineService $stateMachine,
        \App\Models\Investment $investmentModel,
        \Core\EventDispatcher $eventDispatcher,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->outboxService = $outboxService;
        $this->walletService = $walletService;
        $this->withdrawalModel = $withdrawalModel;
        $this->stateMachine = $stateMachine;
        $this->investmentModel = $investmentModel;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
}

    public function handle(int $withdrawalId, int $adminId): array
    {
        try {
            $withdrawal = $this->db->selectOne("SELECT * FROM investment_withdrawals WHERE id = ?", [$withdrawalId]);

            if (!$withdrawal) {
                throw new \Exception('درخواست یافت نشد');
            }

            if ($withdrawal->status !== \App\Models\InvestmentWithdrawal::STATUS_PENDING) {
                throw new \Exception('فقط درخواست‌های در انتظار قابل تأیید هستند');
            }

            $investment = $this->db->selectOne("SELECT * FROM investments WHERE id = ?", [$withdrawal->investment_id]);

            if (!$investment) {
                throw new \Exception('سرمایه‌گذاری یافت نشد');
            }

            $idempotencyKey = "inv_withdrawal_approve_{$withdrawalId}";
            $payload = [
                'user_id' => (int)$withdrawal->user_id,
                'amount' => (string)$withdrawal->amount,
                'currency' => 'usdt',
                'metadata' => [
                    'type' => 'investment_withdrawal',
                    'investment_id' => $investment->id,
                    'withdrawal_id' => $withdrawalId,
                    'description' => 'برداشت سود سرمایه‌گذاری',
                    'idempotency_key' => $idempotencyKey,
                ],
            ];

            $saga = \Core\Container::getInstance()->make(\App\Services\SagaOrchestrator::class);
            $depositResult = null;
            $newStatus = $this->outboxService ? \App\Models\InvestmentWithdrawal::STATUS_PROCESSING : \App\Models\InvestmentWithdrawal::STATUS_COMPLETED;

            $saga->addStep(
                'mark_processing_and_deposit',
                function() use ($withdrawalId, &$depositResult, $payload, $withdrawal) {
                    $updated = $this->db->execute(
                        "UPDATE investment_withdrawals SET status = 'processing' WHERE id = ? AND status = ?", 
                        [$withdrawalId, \App\Models\InvestmentWithdrawal::STATUS_PENDING]
                    );
                    if (!$updated) {
                        throw new \Exception('Race condition: درخواست در حال پردازش توسط شخص دیگری است.');
                    }

                    if ($this->outboxService) {
                        $ok = $this->outboxService->record('investment_withdrawal', $withdrawalId, \App\Events\Registry\EventRegistry::INVESTMENT_MATURED, $payload);
                        if (!$ok) {
                            throw new \Exception('خطا در ثبت رکورد خروجی برای واریز برداشت.');
                        }
                        $depositResult = ['transaction_id' => null];
                    } else {
                        $depositResult = $this->walletService->deposit(
                            (int)$withdrawal->user_id,
                            (string)$withdrawal->amount,
                            'usdt',
                            $payload['metadata']
                        );

                        if (empty($depositResult['success'])) {
                            throw new \Exception('خطا در واریز: ' . ($depositResult['message'] ?? ''));
                        }
                    }
                },
                function(\Throwable $e) use ($withdrawalId, $withdrawal, $idempotencyKey) {
                    // Compensating step: revert status to pending
                    $this->db->execute("UPDATE investment_withdrawals SET status = ? WHERE id = ?", [\App\Models\InvestmentWithdrawal::STATUS_PENDING, $withdrawalId]);
                    
                    // Note: If walletService->deposit succeeded but something else failed, we'd need to deduct the money back. 
                    // But in this step, it's the last action, so if it fails, it didn't happen.
                }
            )->addStep(
                'update_final_status',
                function() use ($withdrawalId, $newStatus, &$depositResult, $withdrawal, $investment) {
                    $this->withdrawalModel->update($withdrawalId, [
                        'status'         => $newStatus,
                        'processed_at'   => date('Y-m-d H:i:s'),
                        'transaction_id' => $depositResult['transaction_id'] ?? null,
                    ]);

                    if ($withdrawal->withdrawal_type === \App\Models\InvestmentWithdrawal::TYPE_FULL_CLOSE) {
                        if ($this->stateMachine->canTransition('investment', $investment->status, \App\Models\Investment::STATUS_CLOSED)) {
                            $this->investmentModel->update($investment->id, [
                                'status'          => \App\Models\Investment::STATUS_CLOSED,
                                'current_balance' => 0
                            ]);

                            \Core\EventDispatcher::getInstance()->dispatchAsync('investment.matured', [
                                'investment_id' => $investment->id,
                                'user_id' => (int)$investment->user_id,
                                'amount' => $investment->amount,
                                'final_balance' => $withdrawal->amount,
                                'matured_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            );

            $saga->execute();

            $this->auditTrail->record('investment.closed', (int)$withdrawal->user_id, [
                'withdrawal_id'   => $withdrawalId,
                'investment_id'   => $investment->id,
                'amount'          => (float)$withdrawal->amount,
                'withdrawal_type' => ($withdrawal->amount >= $investment->amount ? 'full_close' : 'profit_only'),
                'admin_id'        => $adminId,
            ], $adminId);

            $this->eventDispatcher->dispatchAsync('investment.withdrawal_approved', [
                'user_id' => $withdrawal->user_id,
                'amount' => $withdrawal->amount
            ]);
            
            $this->logger->info('investment_withdrawal_approved', ['message' => "Admin {$adminId} approved withdrawal #{$withdrawalId}"]);

            return ['success' => true, 'message' => 'برداشت تأیید و واریز شد'];

        } catch (\Throwable $e) {
            $this->logger->error('investment_withdrawal_approve_failed', [
                'withdrawal_id' => $withdrawalId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
