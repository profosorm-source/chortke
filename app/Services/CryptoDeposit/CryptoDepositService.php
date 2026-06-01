<?php

declare(strict_types=1);

namespace App\Services\CryptoDeposit;

use App\Adapters\CryptoVerificationAdapter;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Settings\AppSettings;
use App\Services\ReconciliationService;
use App\Models\CryptoDepositIntent;
use App\Models\CryptoDeposit;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\OutboxService;

use App\Services\StateMachineService;

class CryptoDepositService
{
    private const ALLOWED_NETWORKS = ['TRC20', 'BNB20', 'ERC20', 'TON', 'SOL'];

    private CryptoDepositIntent $intentModel;
    private CryptoDeposit $depositModel;
    private WalletServiceInterface $wallet;
    private CryptoVerificationAdapter $verifier;
    private AppSettings $appSettings;
    private ReconciliationService $reconciliationService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private ?OutboxService $outbox;
    private StateMachineService $stateMachine;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        CryptoDeposit $depositModel,
        AppSettings $appSettings,
        ?StateMachineService $stateMachine = null,
        ?OutboxService $outbox = null
    ) {        $this->db = $db;
        $this->logger = $logger;

        
        
        $this->depositModel = $depositModel;
        $this->wallet = $walletService;
        $this->verifier = $verifier;
        $this->appSettings = $appSettings;
        $this->reconciliationService = $reconciliationService;
        
        $this->stateMachine = $stateMachine ?? new StateMachineService($this->logger, $this->db);
        $this->outbox = $outbox;
    }

    /**
     * Create a new crypto deposit intent
     */
    public function createIntent(
        int $userId,
        string $network,
        float $requestedAmount,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Payment\CreateCryptoDepositIntentJob::class);
        return $job->handle($userId, $network, $requestedAmount, $ipAddress, $userAgent);
    }


    /**
     * Create a new crypto deposit (direct store from user)
     */
    public function createDeposit(int $userId, array $data): array
    {
        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($userId, $data) {
                // Pessimistic lock check on tx_hash and network to prevent race condition and cross-network bypass (C-01 & C-06, M-02)
                $existingDeposit = $this->depositModel->findByHashAndNetworkForUpdate($data['tx_hash'], $data['network']);
                if ($existingDeposit) {
                    throw new \RuntimeException('این هش تراکنش قبلاً ثبت شده است');
                }

                // دریافت آدرس کیف پول مقصد
                $walletAddress = $data['network'] === 'bnb20' 
                    ? $this->appSettings->get('site_usdt_bnb20_address')
                    : $this->appSettings->get('site_usdt_trc20_address');

                if (!$walletAddress) {
                    throw new \RuntimeException('آدرس کیف پول این شبکه تنظیم نشده است');
                }

                $data['user_id'] = $userId;
                $data['wallet_address'] = $walletAddress;
                $data['verification_status'] = 'pending';
                
                // Set auto_check_deadline (30 mins from now in default timezone)
                $minutes = (int) ($this->appSettings->get('crypto_intent_expire_minutes') ?: \App\Constants\CryptoConstants::DEFAULT_INTENT_EXPIRE_MINUTES);
                $data['auto_check_deadline'] = (new \DateTime())
                    ->modify("+{$minutes} minutes")
                    ->format('Y-m-d H:i:s');
                $data['auto_check_attempts'] = 0;
                $data['created_at'] = \date('Y-m-d H:i:s');
                $data['updated_at'] = \date('Y-m-d H:i:s');

                $deposit = $this->depositModel->create($data);

                if (!$deposit) {
                    throw new \RuntimeException('خطا در ثبت درخواست');
                }
                
                $this->logger->activity('crypto_deposit_requested', "درخواست واریز {$data['amount']} USDT ({$data['network']})", $userId, ['deposit_id' => $deposit->id] ?? []);

                return [
                    'success' => true,
                    'message' => 'درخواست واریز شما ثبت شد و در حال بررسی خودکار است',
                    'deposit_id' => $deposit->id
                ];
            });
        } catch (\Exception $e) {
            // If it's a PDOException with code 23000 (Integrity constraint violation) or duplicate entry
            if ($e instanceof \PDOException && ($e->getCode() === '23000' || \str_contains($e->getMessage(), 'Duplicate entry'))) {
                return ['success' => false, 'message' => 'این هش تراکنش در همین لحظه ثبت شد و امکان ثبت مجدد وجود ندارد.'];
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Approve a crypto deposit (admin action)
     */

    public function approve(int $adminId, int $depositId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Payment\ApproveCryptoDepositJob::class);
        return $job->handle($adminId, $depositId);
    }


    public function reject(int $adminId, int $depositId, string $reason): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Payment\RejectCryptoDepositJob::class);
        return $job->handle($adminId, $depositId, $reason);
    }



    private function isAllowedNetwork(string $network): bool
    {
        return in_array(strtoupper(trim($network)), self::ALLOWED_NETWORKS, true);
    }

    private function isValidTxHash(string $txHash): bool
    {
        $txHash = trim($txHash);
        return $txHash !== '' && strlen($txHash) <= 128 && (bool)preg_match('/^[A-Za-z0-9_-]+$/', $txHash);
    }

    private function recordNotificationOutbox(int $depositId, string $eventType, string $method, array $args): void
    {
        if (!$this->outbox) {
            return;
        }

        $this->outbox->record('crypto_deposit', (string)$depositId, $eventType, [
            'notification' => [
                'method' => $method,
                'args' => $args,
            ],
        ]);
    }

    /**
     * Get site wallet for network
     */
    private function getSiteWallet(string $network): ?string
    {
        $wallets = [
            'TRC20' => $this->appSettings->get('site_wallet_trc20'),
            'BNB20' => $this->appSettings->get('site_wallet_bnb20'),
            'ERC20' => $this->appSettings->get('site_wallet_erc20'),
            'TON' => $this->appSettings->get('site_wallet_ton'),
            'SOL' => $this->appSettings->get('site_wallet_sol'),
        ];

        return $wallets[$network] ?? null;
    }

    /**
     * Generate unique amount for deposit intent
     */
    private function generateUniqueAmount(string $network, float $requestedAmount): float
    {
        $maxAttempts = \App\Constants\CryptoConstants::MAX_UNIQUE_AMOUNT_ATTEMPTS;
        $attempt = 0;
        $cache = \Core\Cache::getInstance();

        do {
            // HIGH-07: Formulate higher precision entropy bounds (8 decimals) to dilute collision density
            $randomAddition = \random_int(1, 9999999) / 100000000;
            $expected = \round($requestedAmount + $randomAddition, 8);

            // Use distributed cache lock to prevent concurrent race condition between threads generating unique amount (C-03)
            $lockKey = "lock_intent_amount_" . md5($network . "_" . (string)$expected);
            if ($cache->lock($lockKey, 10, 2)) {
                // Check global - both open/active intents and recently claimed intents to prevent amount collision replay attacks (C-08 & C-02)
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM crypto_deposit_intents 
                    WHERE network = ? AND expected_amount = ? 
                    AND (status = 'open' OR (status = 'claimed' AND claimed_at > DATE_SUB(NOW(), INTERVAL 7 DAY)))
                ");
                $stmt->execute([$network, $expected]);
                $count = (int)$stmt->fetchColumn();

                if ($count === 0) {
                    return $expected;
                }

                // If collision is detected, release the lock immediately so another attempt can be made
                $cache->forget($lockKey);
            }

            $attempt++;
        } while ($attempt < $maxAttempts);

        // HIGH-07: Assert an atomic failure state instead of emitting duplicate/colliding amounts which allows payment hijacking
        throw new \RuntimeException("امکان تولید شناسه واریز منحصر به فرد در این لحظه وجود ندارد. لطفاً دقایقی دیگر تلاش نمایید.");
    }

    /**
     * Cleanup expired intents (can be called via cron job)
     */

    public function cleanupExpiredIntents(): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Payment\CleanupExpiredCryptoIntentsJob::class);
        return $job->handle();
    }



    /**
     * جستجوی سریع واریزهای کریپتو برای سیستم سرچ مرکزی
     */
    public function quickSearchCryptoDeposits(string $term, int $limit = 5): array
    {
        $term = trim($term);
        if (\strlen($term) > 100) {
            return []; // Defensively reject overly long search terms to protect database performance (C-11 / C-14)
        }

        $query = $this->depositModel->query()
            ->selectRaw("crypto_deposits.id, crypto_deposits.amount, 'crypto' as type, crypto_deposits.verification_status as status, crypto_deposits.created_at, u.full_name, u.email")
            ->leftJoin('users as u', 'u.id', '=', 'crypto_deposits.user_id');

        $this->depositModel->applySearch($query, $term);

        if (!empty($term)) {
            $escaped = addcslashes($term, '%_');
            $like = "%{$escaped}%";
            $query->where(function($sub) use ($like) {
                $sub->orWhere('u.email', 'LIKE', $like);
            });
        }

        return $query->orderBy('crypto_deposits.created_at', 'DESC')
                     ->limit($limit)
                     ->get() ?? [];
    }
    // --- SAGA STEP METHODS FOR APPROVE ---

    public function executeApproveValidate(array $payload): array
    {
        $depositId = $payload['deposit_id'];
        $deposit = $this->depositModel->find($depositId);
        if (!$deposit) {
            throw new \Exception('واریز یافت نشد');
        }

        $currentStatus = $deposit->verification_status ?? 'pending';
        if (!$this->stateMachine->canTransition('crypto_deposit', $currentStatus, 'verified')) {
            throw new \Exception("تغییر وضعیت از وضعیت فعلی ({$currentStatus}) به verified مجاز نیست");
        }
        $payload['user_id'] = $deposit->user_id;
        $payload['amount'] = $deposit->amount;
        $payload['network'] = $deposit->network;
        $payload['tx_hash'] = $deposit->tx_hash;
        $payload['current_status'] = $currentStatus;
        return $payload;
    }

    public function compensateApproveValidate(array $payload, mixed $result, \Throwable $e): void
    {
        // No action needed for validation
    }

    public function executeApproveWallet(array $payload): array
    {
        $depositId = $payload['deposit_id'];
        $adminId = $payload['admin_id'];

        $metadata = [
            'type' => 'crypto_deposit',
            'gateway' => 'usdt_' . $payload['network'],
            'gateway_transaction_id' => $payload['tx_hash'],
            'description' => 'واریز USDT - ' . strtoupper((string)$payload['network']),
            'network' => $payload['network'],
            'tx_hash' => $payload['tx_hash'],
            'deposit_id' => $depositId,
            'approved_by' => $adminId,
        ];

        if ($this->outbox) {
            $ok = $this->outbox->record('crypto_deposit', $depositId, \App\Events\Registry\EventRegistry::CRYPTO_DEPOSIT_CONFIRMED, ['user_id' => $payload['user_id'], 'amount' => $payload['amount'], 'currency' => 'usdt', 'metadata' => $metadata]);
            if (!$ok) {
                throw new \Exception('خطا در ثبت رکورد خروجی برای واریز کریپتو');
            }
        } else {
            $depositResult = $this->wallet->deposit((int)$payload['user_id'], (string)$payload['amount'], 'usdt', $metadata);
            if (!$depositResult['success']) {
                throw new \Exception('خطا در واریز به کیف پول');
            }
            $payload['wallet_transaction_id'] = $depositResult['transaction_id'] ?? null;
        }
        return $payload;
    }

    public function compensateApproveWallet(array $payload, mixed $result, \Throwable $e): void
    {
        $this->logger->warning('saga.compensating.crypto_approve_wallet', ['deposit_id' => $payload['deposit_id']]);
        if (isset($result['wallet_transaction_id']) || !isset($payload['wallet_transaction_id'])) {
            $this->wallet->withdraw((int)$payload['user_id'], (string)$payload['amount'], 'usdt', ['type' => 'saga_compensation', 'deposit_id' => $payload['deposit_id']]);
        }
    }

    public function executeApproveStatus(array $payload): array
    {
        $depositId = $payload['deposit_id'];
        $adminId = $payload['admin_id'];
        $this->depositModel->updateStatus($depositId, 'verified', null, null, $adminId, $payload['wallet_transaction_id'] ?? null);

        $this->logger->info('crypto.deposit.status_transition', [
            'deposit_id' => $depositId,
            'user_id' => $payload['user_id'],
            'from_status' => $payload['current_status'],
            'to_status' => 'verified',
            'operator_id' => $adminId,
            'triggered_by' => 'admin_approve',
        ]);

        $this->recordNotificationOutbox($depositId, 'notification.crypto_deposit_approved', 'send', [
            (int)$payload['user_id'],
            'deposit',
            'واریز کریپتو تأیید شد',
            'تراکنش واریز شما در شبکه ' . strtoupper((string)$payload['network']) . ' به مبلغ ' . $payload['amount'] . ' USDT تأیید شد.',
            ['amount' => $payload['amount'], 'network' => $payload['network'], 'tx_hash' => $payload['tx_hash']]
        ]);

        return $payload;
    }

    public function compensateApproveStatus(array $payload, mixed $result, \Throwable $e): void
    {
        $this->depositModel->updateStatus($payload['deposit_id'], $payload['current_status'], null, null, $payload['admin_id'], null);
    }
}