<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CryptoDeposit;
use App\Adapters\CryptoVerificationAdapter;
use App\Services\ReconciliationService;
use App\Contracts\WalletServiceInterface;
use App\Services\StateMachineService;
use App\Services\OutboxService;
use App\Services\Shared\IdempotencyService;
use App\Contracts\LoggerInterface;
use Core\Database;
use Core\EventDispatcher;
use Core\Container;
use App\Services\SagaOrchestrator;

class VerifyCryptoDepositJob
{
    private array $allowedNetworks = ['TRC20', 'BNB20', 'ERC20', 'TON', 'SOL'];

    private CryptoDeposit $depositModel;
    private CryptoVerificationAdapter $verifier;
    private ReconciliationService $reconciliationService;
    private WalletServiceInterface $wallet;
    private StateMachineService $stateMachine;
    private IdempotencyService $idempotencyService;
    private LoggerInterface $logger;
    private Database $db;
    private EventDispatcher $eventDispatcher;
    private ?OutboxService $outbox;
    public function __construct(
        CryptoDeposit $depositModel,
        CryptoVerificationAdapter $verifier,
        ReconciliationService $reconciliationService,
        WalletServiceInterface $wallet,
        StateMachineService $stateMachine,
        IdempotencyService $idempotencyService,
        LoggerInterface $logger,
        Database $db,
        EventDispatcher $eventDispatcher,
        ?OutboxService $outbox = null
    ) {        $this->depositModel = $depositModel;
        $this->verifier = $verifier;
        $this->reconciliationService = $reconciliationService;
        $this->wallet = $wallet;
        $this->stateMachine = $stateMachine;
        $this->idempotencyService = $idempotencyService;
        $this->logger = $logger;
        $this->db = $db;
        $this->eventDispatcher = $eventDispatcher;
        $this->outbox = $outbox;
}

    public function handle(array $data = []): array
    {
        $depositId = (int)($data['deposit_id'] ?? 0);
        if ($depositId <= 0) {
            return ['auto' => false, 'message' => '????? ????? ??????? ???'];
        }

        return $this->idempotencyService->execute(
            'crypto_deposit.auto_verify',
            $depositId,
            ['deposit_id' => $depositId],
            fn() => $this->tryAutoVerifyInternal($depositId)
        );
    }

    private function tryAutoVerifyInternal(int $depositId): array
    {
        $d = $this->depositModel->find($depositId);
        if (!$d) {
            $this->logger->error('crypto.verify.deposit_not_found', ['deposit_id' => $depositId]);
            return ['auto' => false, 'message' => '????? ???? ???'];
        }

        if (!$this->isAllowedNetwork((string)$d->network) || !$this->isValidTxHash((string)$d->tx_hash)) {
            $this->logger->warning('crypto.verify.invalid_payload', [
                'deposit_id' => $depositId,
                'network' => $d->network ?? null,
                'tx_hash' => $d->tx_hash ?? null,
            ]);
            return $this->moveToManualReview($depositId, '???????? ?????? ???? ????? ?????? ????? ????');
        }

        if ((int)$d->auto_check_attempts >= 10) {
            return $this->moveToManualReview($depositId, '????? ??????? ????? ??? ?? ?? ????');
        }

        $currentStatus = $d->verification_status ?? 'pending';
        if ($this->stateMachine->isTerminalState('crypto_deposit', $currentStatus)) {
            $this->logger->warning('crypto.verify.terminal_state', [
                'deposit_id' => $depositId,
                'status' => $currentStatus
            ]);
            return ['auto' => false, 'message' => '??? ?????? ????? ????? ??? ???'];
        }

        $this->logger->info('crypto.verify.started', [
            'deposit_id' => $depositId,
            'user_id' => $d->user_id,
            'network' => $d->network,
            'amount' => $d->amount,
            'tx_hash' => $d->tx_hash
        ]);

        if ($d->auto_check_deadline) {
            $deadline = new \DateTime($d->auto_check_deadline);
            $now = new \DateTime();

            if ($deadline->getTimestamp() < $now->getTimestamp()) {
                if ($d->verification_status === 'pending') {
                    if ($this->stateMachine->canTransition('crypto_deposit', $currentStatus, 'rejected')) {
                        $this->depositModel->updateStatus($depositId, 'rejected', null, '???? ????? ?????? (?? ?????) ???? ??');

                        $this->logger->info('crypto.deposit.status_transition', [
                            'deposit_id' => $depositId,
                            'user_id' => $d->user_id,
                            'from_status' => $currentStatus,
                            'to_status' => 'rejected',
                            'operator_id' => null,
                            'triggered_by' => 'auto_verify_timeout',
                        ]);

                        $this->logger->warning('crypto.verify.timeout', [
                            'deposit_id' => $depositId,
                            'user_id' => $d->user_id,
                            'deadline' => $d->auto_check_deadline
                        ]);
                    }

                    return ['auto' => false, 'message' => '?? ?? (????? ???? ?? ?????)'];
                }
            }
        }

        $this->depositModel->update($depositId, [
            'auto_check_attempts' => (int)$d->auto_check_attempts + 1
        ]);

        try {
            $result = $this->verifier->verify(
                (string)$d->network,
                (string)$d->tx_hash,
                (string)$d->from_wallet,
                (string)$d->to_wallet,
                (float)$d->amount
            );
        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.api_failed', [
                'channel' => 'crypto',
                'deposit_id' => $depositId,
                'user_id' => $d->user_id,
                'network' => $d->network,
                'tx_hash' => $d->tx_hash,
                'error' => $e->getMessage(),
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->moveToManualReview($depositId, '??? ?? ????? ?? Explorer');
        }

        if (($result['status'] ?? '') === 'verified') {
            try {
                $saga = Container::getInstance()->make(SagaOrchestrator::class);
                $lockedStatus = null;
                $okResult = null;
                $reconciliation = null;

                $saga->addStep(
                    'lock_and_validate',
                    function () use ($depositId, &$lockedStatus) {
                        $stmt = $this->db->prepare("SELECT verification_status FROM crypto_deposits WHERE id = ? FOR UPDATE");
                        $stmt->execute([$depositId]);
                        $lockedStatus = $stmt->fetchColumn();

                        if (!$lockedStatus) {
                            throw new \Exception('?????? ???? ???');
                        }

                        if (in_array($lockedStatus, ['verified', 'auto_verified'])) {
                            throw new \Exception('ALREADY_VERIFIED');
                        }

                        if (!$this->stateMachine->canTransition('crypto_deposit', $lockedStatus, 'auto_verified')) {
                            throw new \Exception("????? ????? ?? auto_verified ?? ????? ???? ({$lockedStatus}) ???? ????");
                        }
                        return true;
                    },
                    function () {}
                )->addStep(
                    'wallet_deposit',
                    function () use ($d, $depositId, &$okResult) {
                        $stmtWallet = $this->db->prepare("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE");
                        $stmtWallet->execute([(int)$d->user_id]);

                        $okResult = $this->wallet->deposit((int)$d->user_id, (string)$d->amount, 'usdt', [
                            'type' => 'crypto_deposit',
                            'deposit_id' => $depositId,
                            'network' => (string)$d->network,
                            'tx_hash' => (string)$d->tx_hash,
                            'idempotency_key' => 'crypto_deposit_' . $depositId,
                        ]);

                        if (!($okResult['success'] ?? false)) {
                            throw new \Exception('???? ??? ??? ?????? ???');
                        }
                        return $okResult;
                    },
                    function (\Throwable $e) use ($depositId, $d, &$okResult) {
                        $this->logger->warning('saga.compensating.auto_verify_wallet', ['deposit_id' => $depositId]);
                        if (isset($okResult) && ($okResult['success'] ?? false)) {
                            $this->wallet->withdraw((int)$d->user_id, (string)$d->amount, 'usdt', ['type' => 'saga_compensation', 'deposit_id' => $depositId]);
                        }
                    }
                )->addStep(
                    'update_status_and_reconcile',
                    function ($okResult) use ($d, $depositId, $result, &$lockedStatus, &$reconciliation) {
                        $this->depositModel->updateStatus(
                            $depositId,
                            'auto_verified',
                            $result['details'] ?? null,
                            null,
                            null,
                            $okResult['transaction_id'] ?? null
                        );

                        $this->logger->info('crypto.deposit.status_transition', [
                            'deposit_id' => $depositId,
                            'user_id' => $d->user_id,
                            'from_status' => $lockedStatus,
                            'to_status' => 'auto_verified',
                            'operator_id' => null,
                            'triggered_by' => 'auto_verify_success',
                        ]);

                        $reconciliation = $this->reconciliationService->reconcilePayment([
                            'transaction_id' => (string)$d->tx_hash,
                            'reference_id' => 'crypto_deposit_' . $depositId,
                            'user_id' => (int)$d->user_id,
                            'amount' => (float)$d->amount,
                            'currency' => 'usdt',
                            'status' => 'success',
                            'gateway' => 'crypto_' . strtolower((string)$d->network),
                            'description' => "????? crypto deposit - Network: {$d->network}, Tx: {$d->tx_hash}",
                            'timestamp' => time(),
                            'is_internal' => true,
                        ]);

                        if (!$reconciliation['success']) {
                            throw new \Exception('??? ?? ????? ???? ??????: ' . ($reconciliation['message'] ?? 'Unknown error'));
                        }

                        $this->recordNotificationOutbox($depositId, 'notification.crypto_deposit_auto_verified', 'send', [
                            (int)$d->user_id,
                            'deposit',
                            '????? ?????? ?????? ????? ??',
                            '?????? ????? ?????? ??? ?? ???? ' . strtoupper((string)$d->network) . ' ?? ???? ' . $d->amount . ' USDT ?? ?????? ????? ? ?? ??? ??? ??? ????? ??.',
                            [
                                'amount' => $d->amount,
                                'network' => $d->network,
                                'tx_hash' => $d->tx_hash,
                            ]
                        ]);

                        return true;
                    },
                    function (\Throwable $e) use ($depositId) {
                        $this->logger->warning('saga.compensating.auto_verify_status', ['deposit_id' => $depositId]);
                    }
                );

                $saga->execute();

                $this->eventDispatcher->dispatchAsync('crypto.deposit.confirmed', [
                    'deposit_id' => $depositId,
                    'user_id' => (int)$d->user_id,
                    'amount' => $d->amount,
                    'network' => $d->network,
                    'tx_hash' => $d->tx_hash,
                    'admin_id' => null,
                    'auto_verified' => true
                ]);

                if (!$this->outbox) {
                    try {
                        $this->eventDispatcher->dispatchAsync('notification.requested', [
                            'user_id' => (int)$d->user_id,
                            'channel' => 'deposit',
                            'title' => '????? ?????? ?????? ????? ??',
                            'body' => '?????? ????? ?????? ??? ?? ???? ' . strtoupper((string)$d->network) . ' ?? ???? ' . $d->amount . ' USDT ?? ?????? ????? ? ?? ??? ??? ??? ????? ??.',
                            'data' => [
                                'amount' => $d->amount,
                                'network' => $d->network,
                                'tx_hash' => $d->tx_hash,
                            ]
                        ]);
                    } catch (\Throwable $notifErr) {
                        $this->logger->error('crypto.verify.auto_success.notification_failed', [
                            'deposit_id' => $depositId,
                            'error' => $notifErr->getMessage()
                        ]);
                    }
                }

                if ($reconciliation !== null && !$reconciliation['success']) {
                    try {
                        $this->eventDispatcher->dispatchAsync('admin_notification.requested', [
                            'type' => 'crypto_reconciliation_failure',
                            'title' => '???? ????? ????? ??????',
                            'body' => "????? ???? ?? ????? ?????? ?????? ?? ????? ????? {$depositId} ? ????? {$d->user_id} ?? ???? {$d->amount} ???.",
                            'data' => [
                                'deposit_id' => $depositId,
                                'user_id' => $d->user_id,
                                'network' => $d->network,
                                'tx_hash' => $d->tx_hash,
                                'message' => $reconciliation['message'] ?? 'Unknown error'
                            ]
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->error('crypto.verify.reconciliation_failure_alert_failed', ['deposit_id' => $depositId, 'error' => $e->getMessage()]);
                    }
                }

                return ['auto' => true, 'message' => '????? ?????? ????'];
            } catch (\Exception $e) {
                $this->logger->error('crypto.verify.auto_deposit_failed', [
                    'deposit_id' => $depositId,
                    'user_id' => $d->user_id,
                    'error' => $e->getMessage()
                ]);
                return $this->moveToManualReview($depositId, '??? ?? ????? ??????: ' . $e->getMessage());
            }
        } elseif (($result['status'] ?? '') === 'mismatch') {
            return $this->moveToManualReview($depositId, $result['reason'] ?? '??? ????? ???????');
        } elseif (($result['status'] ?? '') === 'pending') {
            return ['auto' => false, 'message' => $result['reason'] ?? '?????? ?? ?????? ????? ????'];
        } else {
            if ((int)$d->auto_check_attempts >= 10) {
                return $this->moveToManualReview($depositId, '??? ?????? ?? ??????? ?? ?? ??????? ????: ' . ($result['reason'] ?? '????? ?????? ??????'));
            }
            return ['auto' => false, 'message' => '???? ???? ?? ????? ?? ???? ??????: ' . ($result['reason'] ?? '?????? ?? Explorer ??? ???')];
        }
    }

    private function moveToManualReview(int $depositId, string $reason): array
    {
        $this->depositModel->updateStatus($depositId, 'manual_review', null, $reason);

        $d = $this->depositModel->find($depositId);
        
        $this->logger->info('crypto.deposit.status_transition', [
            'deposit_id' => $depositId,
            'user_id' => $d->user_id ?? 0,
            'from_status' => 'pending',
            'to_status' => 'manual_review',
            'operator_id' => null,
            'triggered_by' => 'auto_verify_failed',
            'reason' => $reason
        ]);

        return ['auto' => false, 'message' => '????? ?? ????? ????: ' . $reason];
    }

    private function isAllowedNetwork(string $network): bool
    {
        return in_array(strtoupper($network), $this->allowedNetworks, true);
    }

    private function isValidTxHash(string $hash): bool
    {
        $hash = trim($hash);
        return $hash !== '' && strlen($hash) >= 10;
    }

    private function recordNotificationOutbox(int $referenceId, string $event, string $action, array $payload): void
    {
        if ($this->outbox) {
            $this->outbox->record('crypto_deposit', $referenceId, $event, $action, $payload);
        }
    }
}
