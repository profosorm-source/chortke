<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Services\Payment\PaymentBaseService;
use Core\Database;
use App\Exceptions\BusinessException;
use App\Contracts\LoggerInterface;
use App\Models\Withdrawal;
use App\Contracts\WalletServiceInterface;
use App\Services\ReconciliationService;
use App\Services\AuditTrail;
use App\Services\SagaOrchestrator;
use App\Services\Shared\IdempotencyService;
use App\Events\WithdrawalApprovedEvent;
use App\Events\WithdrawalEvent;
use Core\EventDispatcher;

/**
 * WithdrawalAdminService - مدیریت فرآیندهای مالی توسط ادمین و سیستم
 */
class WithdrawalAdminService 
{
    private WalletServiceInterface $wallet;
    private LoggerInterface $logger;
    private Database $db;
    private ReconciliationService $reconciliation;
    private AuditTrail $auditTrail;
    private Withdrawal $model;
    private IdempotencyService $idempotencyService;
    private SagaOrchestrator $saga;

    protected function validateAmount(float $amount): array
    {
        $errors = [];

        if ($amount <= 0) {
            $errors[] = '???? ???? ?????? ?? ??? ????';
        }

        if ($amount < 1000) {
            $errors[] = '????? ???? ???? ????? ???';
        }

        if ($amount > 50000000) {
            $errors[] = '?????? ???? ?? ?????? ????? ???';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    protected function logSuccess(string $operation, array $context): void
    {
        $this->logger->info("payment.{$operation}.success", $context);
    }

    protected function logPaymentError(string $operation, string $error, array $context = []): void
    {
        $this->logger->error("payment.{$operation}.failed", array_merge($context, ['error' => $error]));
    }

    protected function logStart(string $operation, array $context): void
    {
        $this->logger->info("payment.{$operation}.started", $context);
    }

    public function __construct(
        Database $db,
        WalletServiceInterface $wallet,
        ReconciliationService $reconciliation,
        AuditTrail $auditTrail,
        Withdrawal $model,
        LoggerInterface $logger,
        IdempotencyService $idempotencyService,
        SagaOrchestrator $saga
    ) {
        $this->logger = $logger;
        $this->db = $db;
        $this->wallet = $wallet;
        $this->reconciliation = $reconciliation;
        $this->auditTrail = $auditTrail;
        $this->model = $model;
        $this->idempotencyService = $idempotencyService;
        $this->saga = $saga;
    }

    public function adminApprove(int $withdrawalId, int $adminId, ?string $paymentReference = null, ?string $idempotencyKey = null): array
    {
        $result = $this->idempotencyService->executeWithTransaction(
            'withdrawal.adminApprove',
            $adminId,
            [
                'withdrawal_id' => $withdrawalId,
                'admin_id' => $adminId,
                'payment_reference' => $paymentReference,
            ],
            function () use ($withdrawalId, $adminId, $paymentReference) {
            
            $saga = $this->saga;
            $withdrawalSnapshot = null;
            $skipEvent = false;

            $saga->addStep(
                'validate_and_settle',
                function () use ($withdrawalId, &$withdrawalSnapshot, &$skipEvent) {
                    $withdrawal = $this->db->query("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);
                    
                    if (!$withdrawal || $withdrawal->status === 'completed') {
                        $skipEvent = true;
                        return ['success' => true, 'message' => 'قبلاً تأیید شده است'];
                    }

                    if (!$this->wallet->completeWithdrawal((int)$withdrawal->user_id, (string)$withdrawal->amount, (string)$withdrawal->currency, (string)$withdrawal->transaction_id)) {
                        throw new \RuntimeException('خطا در تسویه کیف پول');
                    }

                    $withdrawalSnapshot = [
                        'user_id' => (int)$withdrawal->user_id,
                        'amount' => (float)$withdrawal->amount,
                        'currency' => (string)$withdrawal->currency,
                        'transaction_id' => (string)$withdrawal->transaction_id,
                    ];
                    
                    return $withdrawalSnapshot;
                },
                function (\Throwable $e) use ($withdrawalId, &$withdrawalSnapshot) {
                    // Compensate: If we settled the wallet but the next steps failed, we technically should revert the settlement
                    // However, `completeWithdrawal` usually just removes the hold, which is tricky to reverse. 
                    // A proper rollback is handled by the DB transaction.
                    $this->logger->warning('saga.compensating.withdrawal_admin_settle', ['withdrawal_id' => $withdrawalId]);
                }
            )->addStep(
                'update_status_and_audit',
                function () use ($withdrawalId, $adminId, $paymentReference, &$withdrawalSnapshot, &$skipEvent) {
                    if ($skipEvent) return true;

                    $this->model->updateStatus($withdrawalId, 'completed', null, $adminId);
                    
                    $this->auditTrail->record('withdrawal.admin_approved', $withdrawalSnapshot['user_id'], [
                        'id' => $withdrawalId,
                        'payment_reference' => $paymentReference,
                    ], $adminId);

                    return true;
                },
                function (\Throwable $e) use ($withdrawalId) {
                    $this->logger->warning('saga.compensating.withdrawal_admin_status', ['withdrawal_id' => $withdrawalId]);
                }
            );

            $saga->execute();

            if ($skipEvent) {
                return ['success' => true, 'message' => 'قبلاً تأیید شده است', '_skip_event' => true];
            }

            return [
                'success' => true,
                'message' => 'تأیید شد',
                '_withdrawal_snapshot' => $withdrawalSnapshot,
            ];
        },
            $idempotencyKey
        );

        // 🚀 رویداد را بعد از commit تراکنش به صورت async ارسال می‌کنیم
        if (!empty($result['success']) && empty($result['_skip_event'])) {
            $snap = $result['_withdrawal_snapshot'];
            EventDispatcher::getInstance()->dispatchAsync(
                WithdrawalApprovedEvent::class,
                new WithdrawalApprovedEvent(
                    $snap['user_id'],
                    $withdrawalId,
                    $snap['amount'],
                    $snap['currency'],
                    $adminId
                )
            );
            EventDispatcher::getInstance()->dispatchAsync(
                WithdrawalEvent::class,
                new WithdrawalEvent([
                    'action' => 'approved',
                    'user_id' => $snap['user_id'],
                    'withdrawal_id' => $withdrawalId,
                    'amount' => $snap['amount'],
                    'currency' => $snap['currency'],
                    'admin_id' => $adminId,
                ])
            );
        }
        unset($result['_withdrawal_snapshot'], $result['_skip_event']);

        return $result;
    }

    public function adminReject(int $withdrawalId, int $adminId, ?string $reason = null, ?string $idempotencyKey = null): array
    {
        $result = $this->idempotencyService->executeWithTransaction(
            'withdrawal.adminReject',
            $adminId,
            [
                'withdrawal_id' => $withdrawalId,
                'admin_id' => $adminId,
                'reason' => $reason,
            ],
            function () use ($withdrawalId, $adminId, $reason) {
                $saga = $this->saga;
                $skip = false;

                $saga->addStep(
                    'validate_and_refund',
                    function () use ($withdrawalId, &$skip) {
                        $withdrawal = $this->model->lockForUpdate($withdrawalId);

                        if (!$withdrawal || $withdrawal->status === 'rejected') {
                            $skip = true;
                            return ['success' => true, 'message' => 'قبلاً رد شده است', '_skip_event' => true];
                        }

                        if (!$this->wallet->cancelWithdrawal((int)$withdrawal->user_id, (string)$withdrawal->amount, (string)$withdrawal->currency, (string)$withdrawal->transaction_id)) {
                            throw new \RuntimeException('خطا در بازگشت وجه');
                        }

                        return true;
                    },
                    function (\Throwable $e) use ($withdrawalId) {
                        $this->logger->warning('saga.compensating.withdrawal_admin_refund', ['withdrawal_id' => $withdrawalId]);
                    }
                )->addStep(
                    'update_status',
                    function () use ($withdrawalId, $adminId, $reason, &$skip) {
                        if ($skip) return true;

                        $this->model->updateStatus($withdrawalId, 'rejected', $reason, $adminId);
                        return true;
                    },
                    function (\Throwable $e) use ($withdrawalId) {
                        $this->logger->warning('saga.compensating.withdrawal_admin_status', ['withdrawal_id' => $withdrawalId]);
                    }
                );

                $saga->execute();

                if ($skip) {
                    return ['success' => true, 'message' => 'قبلاً رد شده است', '_skip_event' => true];
                }

                $stmt = $this->db->prepare('SELECT user_id, amount, currency FROM withdrawals WHERE id = ? LIMIT 1');
                $stmt->execute([$withdrawalId]);
                $withdrawal = $stmt->fetch(\PDO::FETCH_OBJ);

                $eventPayload = null;
                if ($withdrawal) {
                    $eventPayload = [
                        'action' => 'cancelled',
                        'user_id' => (int)$withdrawal->user_id,
                        'withdrawal_id' => $withdrawalId,
                        'amount' => (float)$withdrawal->amount,
                        'currency' => $withdrawal->currency,
                        'reason' => $reason,
                        'admin_id' => $adminId,
                    ];
                }

                return ['success' => true, 'message' => 'رد شد', '_event_payload' => $eventPayload];
            },
            $idempotencyKey
        );

        if (!empty($result['success']) && empty($result['_skip_event']) && !empty($result['_event_payload'])) {
            EventDispatcher::getInstance()->dispatchAsync(
                WithdrawalEvent::class,
                new WithdrawalEvent($result['_event_payload'])
            );
        }

        unset($result['_event_payload'], $result['_skip_event']);
        return $result;
    }

    public function autoResolveStuck(int $adminBotId = 0, int $stableMinutes = 30, int $limit = 50): array
    {
        $candidates = $this->reconciliation->findAutoFixCandidates($stableMinutes, $limit);
        $result = ['scanned' => 0, 'fixed' => 0];

        foreach ($candidates as $c) {
            $result['scanned']++;
            if ($this->reconciliation->markReviewInProgress((int)$c->review_id, $adminBotId)) {
                $res = $this->adminReject((int)$c->withdrawal_id, $adminBotId, 'Auto-resolved');
                if ($res['success']) {
                    $result['fixed']++;
                    $this->reconciliation->markReviewAutoResolved((int)$c->review_id, $adminBotId, 'Success');
                }
            }
        }
        return $result;
    }
}




