<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Contracts\LoggerInterface;
use Core\Database;
use App\Contracts\WalletServiceInterface;
use App\Services\DistributedLockService;
use Core\IdempotencyKey;
use App\Services\Wallet\WalletQueryService;
use App\Services\Wallet\WalletMutationService;
use App\Services\Settings\AppSettings;
use Core\EventDispatcher;
use App\Services\OutboxService;

class WalletService implements WalletServiceInterface
{

    private EventDispatcher $eventDispatcher;
    private Database $db;
    private LoggerInterface $logger;
    private DistributedLockService $lockService;
    private WalletQueryService $queryService;
    private WalletMutationService $mutationService;
    private AppSettings $appSettings;
    private EventDispatcher $events;
    private ?OutboxService $outbox;
    private IdempotencyKey $idempotencyKey;

    private array $supportedCurrencies;

    public function __construct(
        EventDispatcher $eventDispatcher,
        Database $db,
        LoggerInterface $logger,
        IdempotencyKey $idempotencyKey,
        DistributedLockService $lockService,
        WalletQueryService $queryService,
        WalletMutationService $mutationService,
        AppSettings $appSettings,
        ?OutboxService $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->lockService = $lockService;
        $this->queryService = $queryService;
        $this->mutationService = $mutationService;
        $this->appSettings = $appSettings;
        $this->events = $eventDispatcher;
        $this->idempotencyKey = $idempotencyKey;
        $this->outbox = $outbox;

        $this->supportedCurrencies = ['irt', 'usdt'];
        $configuredCurrencies = $appSettings->get('wallet_supported_currencies');
        if (is_array($configuredCurrencies) && !empty($configuredCurrencies)) {
            $this->supportedCurrencies = array_map('strtolower', $configuredCurrencies);
        }
    }

    // =========================================================================
    // BOILERPLATE / INFRASTRUCTURE WRAPPER
    // =========================================================================

    /**
     * Executes a wallet operation safely with distributed locks, DB transactions, and idempotency.
     */
    private function executeAtomicOperation(int $userId, string $action, string $amount, string $currency, array $metadata, callable $logic): array|object|bool
    {
        $currency = strtolower($currency);
        $this->validateCurrency($currency);

        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        $requestId = $metadata['request_id'] ?? (function_exists('get_request_id') ? get_request_id() : uniqid('req_'));
        $ipAddress = $metadata['ip_address'] ?? (function_exists('get_client_ip') ? get_client_ip() : '127.0.0.1');
        $deviceFingerprint = $metadata['device_fingerprint'] ?? (function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : 'system');
        $logId = strtoupper($action) . "_{$requestId}";

        // Idempotency Generation
        $uniqueParts = array_filter([
            $userId, $action, $amount, $currency,
            $metadata['gateway_transaction_id'] ?? '',
            $metadata['ref_id'] ?? '',
            $ipAddress
        ], fn($v) => $v !== '');
        
        $idempotencyKeyStr = $metadata['idempotency_key'] ?? hash('sha256', implode('|', $uniqueParts));

        try {
            return $this->idempotencyKey->wrapInstance($idempotencyKeyStr, $userId, "wallet_{$action}", function() use (
                $userId, $amount, $currency, $metadata, $idempotencyKeyStr,
                $requestId, $ipAddress, $deviceFingerprint, $logId, $logic, $action
            ) {
                return $this->lockService->synchronized("wallet:mut:{$userId}", function () use (
                    $userId, $amount, $currency, $metadata, $idempotencyKeyStr,
                    $requestId, $ipAddress, $deviceFingerprint, $logId, $logic, $action
                ) {
                    $startedTransaction = !$this->db->inTransaction();

                    try {
                        if ($startedTransaction) {
                            $this->db->beginTransaction();
                        }

                        $result = $logic($requestId, $ipAddress, $deviceFingerprint, $idempotencyKeyStr);

                        if ($this->outbox && is_array($result) && !empty($result['success'])) {
                            $this->outbox->record('wallet_transaction', (string)$userId, "wallet.{$action}.completed", [
                                'user_id' => $userId,
                                'transaction_id' => $result['transaction_id'] ?? null,
                                'result' => $result
                            ]);
                        }

                        if ($startedTransaction) {
                            $this->db->commit();
                        }

                        $this->logger->info("wallet.{$action}.success", [
                            'channel' => 'wallet', 'log_id' => $logId, 'user_id' => $userId,
                            'amount' => $amount, 'currency' => $currency
                        ]);

                        return is_array($result) ? $this->standardizeResponse($result) : $result;

                    } catch (\Throwable $e) {
                        if ($startedTransaction && $this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        throw $e;
                    }
                }, 15, 10);
            }, [
                'amount' => $amount,
                'currency' => $currency,
                'ip' => $ipAddress,
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Failed to acquire lock')) {
                $this->logger->warning('wallet.lock_timeout', ['user_id' => $userId, 'action' => $action, 'error' => $e->getMessage()]);
                // ✅ تبدیل lock timeout به TransientException — IdempotencyKey این را retryable می‌داند
                throw new \Core\Exceptions\TransientException(
                    'سیستم در حال حاضر شلوغ است، لطفاً لحظاتی بعد تلاش کنید',
                    503,  // Service Unavailable
                    $e
                );
            }
            throw $e;
        }
    }

    private function validateCurrency(string $currency): void
    {
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            $supportedList = implode("'، '", $this->supportedCurrencies);
            throw new \InvalidArgumentException("ارز '{$currency}' پشتیبانی نمی‌شود. فقط '{$supportedList}' معتبر است.");
        }
    }

    private function standardizeResponse(array $response): array
    {
        return [
            'success'        => (bool)($response['success'] ?? false),
            'transaction_id' => $response['transaction_id'] ?? null,
            'message'        => $response['message'] ?? '',
            'new_balance'    => isset($response['new_balance']) ? (string)$response['new_balance'] : null,
            'amount'         => isset($response['amount']) ? (string)$response['amount'] : null,
            'currency'       => $response['currency'] ?? null,
            'status'         => $response['status'] ?? null,
            'error'          => $response['error'] ?? null,
            'balance_before' => isset($response['balance_before']) ? (string)$response['balance_before'] : null,
            'balance_after'  => isset($response['balance_after']) ? (string)$response['balance_after'] : null,
        ];
    }

    // =========================================================================
    // MUTATIONS (DELEGATED TO MUTATION SERVICE)
    // =========================================================================

    public function deposit(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->executeAtomicOperation($userId, 'deposit', $amount, $currency, $metadata, 
            fn($reqId, $ip, $device, $idemKey) => $this->mutationService->processDeposit($userId, $amount, $currency, array_merge($metadata, ['idempotency_key' => $idemKey]), $reqId, $ip, $device)
        );
    }
    
    public function depositInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        // For backwards compatibility when already in transaction
        return $this->deposit($userId, $amount, $currency, $metadata);
    }

    public function withdraw(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->executeAtomicOperation($userId, 'withdraw', $amount, $currency, $metadata, 
            fn($reqId, $ip, $device, $idemKey) => $this->mutationService->processWithdraw($userId, $amount, $currency, array_merge($metadata, ['idempotency_key' => $idemKey]), $reqId, $ip, $device)
        );
    }
    
    public function withdrawInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->withdraw($userId, $amount, $currency, $metadata);
    }

    public function pay(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->executeAtomicOperation($userId, 'pay', $amount, $currency, $metadata, 
            fn($reqId, $ip, $device, $idemKey) => $this->mutationService->processPay($userId, $amount, $currency, array_merge($metadata, ['idempotency_key' => $idemKey]), $reqId, $ip, $device)
        );
    }

    public function transfer(int $fromUserId, int $toUserId, string $amount, string $currency = 'irt', string $description = ''): ?object
    {
        $this->events->dispatchSync(
            \App\Events\WalletTransferInitiatingEvent::class,
            new \App\Events\WalletTransferInitiatingEvent($fromUserId, $toUserId, $amount, $currency)
        );

        // We wrap transfer under the lock of the first user ID to prevent deadlocks
        $firstId  = min($fromUserId, $toUserId);
        
        $metadata = ['type' => 'transfer', 'to_user_id' => $toUserId];
        $result = $this->executeAtomicOperation($firstId, 'transfer', $amount, $currency, $metadata, 
            fn() => $this->mutationService->processTransfer($fromUserId, $toUserId, $amount, $currency, $description)
        );
        return is_object($result) ? $result : null;
    }

    // Backward compatibility for escrows, cancels, completions
    public function completeWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool
    {
        if (!$transactionId) {
            return false;
        }

        return $this->mutationService->completeWithdrawal($transactionId, $userId);
    }

    public function cancelWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool
    {
        if (!$transactionId) {
            return false;
        }

        return $this->mutationService->cancelWithdrawal($transactionId, $userId);
    }

    public function reverseTransaction(string $transactionId, ?int $adminId = null, string $reason = ''): bool
    {
        return $this->mutationService->reverseTransaction($transactionId, $adminId, $reason);
    }

    // =========================================================================
    // QUERIES (DELEGATED TO QUERY SERVICE)
    // =========================================================================

    public function getOrCreateWallet(int $userId): ?object { return $this->queryService->getOrCreateWallet($userId); }
    public function getWalletBalances(int $userId): array { return $this->queryService->getWalletBalances($userId); }
    public function canWithdraw(int $userId, string $amount, string $currency = 'irt'): array { return $this->queryService->canWithdraw($userId, $amount, $currency); }
    public function getWalletSummary(int $userId): object { return $this->queryService->getWalletSummary($userId); }
    public function getUserTransactions(int $userId, int $limit, int $offset, array $filters = []): array { return $this->queryService->getUserTransactions($userId, $limit, $offset, $filters); }
    public function countUserTransactions(int $userId, array $filters = []): int { return $this->queryService->countUserTransactions($userId, $filters); }
    public function getAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null, int $limit = 50, int $offset = 0): array { return $this->queryService->getAllTransactions($status, $type, $currency, $limit, $offset); }
    public function countAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null): int { return $this->queryService->countAllTransactions($status, $type, $currency); }
    public function findTransactionById(int $id): ?object { return $this->queryService->findTransactionById($id); }
    public function quickSearchTransactions(string $term, ?int $userId = null, int $limit = 5): array { return $this->queryService->quickSearchTransactions($term, $userId, $limit); }
    public function getBalance(int $userId, string $currency = 'irt'): string { return $this->queryService->getBalance($userId, $currency); }
    public function getBalanceForUpdate(int $userId, string $currency = 'irt'): string { return $this->queryService->getBalanceForUpdate($userId, $currency); }
    public function isWalletFrozen(int $userId): bool { return $this->queryService->isWalletFrozen($userId); }
    public function hasBalance(int $userId, string $amount, string $currency = 'irt'): bool { 
        $scale = $currency === 'usdt' ? 8 : 4;
        return bccomp($this->getBalance($userId, $currency), $amount, $scale) >= 0; 
    }
}