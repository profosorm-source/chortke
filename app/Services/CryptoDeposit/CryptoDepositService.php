<?php

declare(strict_types=1);

namespace App\Services\CryptoDeposit;

use App\Adapters\CryptoVerificationAdapter;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\SettingService;
use App\Services\ReconciliationService;
use App\Models\CryptoDepositIntent;
use App\Models\CryptoDeposit;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\OutboxService;

use App\Services\StateMachineService;

class CryptoDepositService extends \App\Services\BaseService
{
    private const ALLOWED_NETWORKS = ['TRC20', 'BNB20', 'ERC20', 'TON', 'SOL'];

    private CryptoDepositIntent $intentModel;
    private CryptoDeposit $depositModel;
    private NotificationServiceInterface $notifier;
    private WalletServiceInterface $wallet;
    private CryptoVerificationAdapter $verifier;
    private SettingService $settingService;
    private ReconciliationService $reconciliationService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private ?OutboxService $outbox;
    private StateMachineService $stateMachine;

    public function __construct(
        Database $db,
        WalletServiceInterface $walletService,
        NotificationServiceInterface $notificationService,
        CryptoDepositIntent $intentModel,
        CryptoDeposit $depositModel,
        LoggerInterface $logger,
        CryptoVerificationAdapter $verifier,
        SettingService $settingService,
        ReconciliationService $reconciliationService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        ?StateMachineService $stateMachine = null,
        ?OutboxService $outbox = null,
        ?\Core\EventDispatcher $eventDispatcher = null
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->intentModel = $intentModel;
        $this->depositModel = $depositModel;
        $this->notifier = $notificationService;
        $this->wallet = $walletService;
        $this->verifier = $verifier;
        $this->settingService = $settingService;
        $this->reconciliationService = $reconciliationService;
        $this->fraudGuard = $fraudGuard;
        $this->stateMachine = $stateMachine ?? new StateMachineService($logger, $db);
        $this->outbox = $outbox;
        $this->eventDispatcher = $eventDispatcher ?? \Core\EventDispatcher::getInstance();
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
        $network = strtoupper(trim($network));
        $intentValidation = $this->validateCryptoIntentInput($userId, $network, $requestedAmount);
        if ($intentValidation !== null) {
            return $intentValidation;
        }

        // LOW-09: Defensively sanitize raw user-supplied IP addresses to safeguard system telemetry
        $cleanIp = null;
        if ($ipAddress !== null) {
            $cleanIp = \filter_var($ipAddress, \FILTER_VALIDATE_IP) ?: null;
        }

        $this->logger->info('crypto.intent.create.started', [
            'user_id' => $userId,
            'network' => $network,
            'requested_amount' => $requestedAmount
        ]);

        // 🛡️ گیت ضدتقلب تراکنش کریپتو (Velocity check & Global policies)
        $risk = $this->fraudGuard->checkAction($userId, 'crypto.deposit', [
            'amount'      => $requestedAmount,
            'currency'    => 'usdt',
            'network'     => $network,
            'ip'          => $cleanIp,
            'user_agent'  => $userAgent
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('crypto.intent_blocked_by_fraud_guard', [
                'user_id' => $userId,
                'amount'  => $requestedAmount,
                'reason'  => $risk['reason']
            ]);
            return ['success' => false, 'message' => 'امکان ثبت درخواست شارژ رمزارز به دلایل امنیتی مسدود شد. دلیل: ' . ($risk['reason'] === 'velocity_limit' ? 'تجاوز از سقف مجاز واریز کریپتو' : $risk['reason'])];
        }

        $expireMinutes = (int) $this->settingService->get('crypto_intent_expire_minutes', \App\Constants\CryptoConstants::DEFAULT_INTENT_EXPIRE_MINUTES);

        $open = $this->intentModel->getOpenIntentForUser($userId);
        if ($open && \strtotime($open->expires_at) < \time()) {
            // Auto-expire it right here to avoid blocking new intent creations (H-01)
            $this->intentModel->expireIfPassed((int)$open->id);
            $open = null;
        }

        if ($open) {
            $this->logger->info('crypto.intent.existing', [
                'user_id' => $userId,
                'intent_id' => $open->id ?? null
            ]);
            return [
                'success' => true,
                'message' => 'شما یک درخواست فعال دارید',
                'intent' => $open,
            ];
        }

        $toWallet = $this->getSiteWallet($network);
        if (!$toWallet) {
            $this->logger->error('crypto.intent.no_wallet', [
                'user_id' => $userId,
                'network' => $network
            ]);
            return ['success' => false, 'message' => 'ولت این شبکه تنظیم نشده است'];
        }

        // H-01: Auto-cleanup expired intents before creating a new one to prevent memory leak / stale claims
        try {
            $this->cleanupExpiredIntents();
        } catch (\Throwable $cleanupErr) {
            $this->logger->warning('crypto.intent.cleanup.failed', ['error' => $cleanupErr->getMessage()]);
        }

        $maxRetryIntents = 5;
        $attempt = 0;
        $id = null;
        $expected = null;
        $expiresAt = null;

        try {
            while ($attempt < $maxRetryIntents) {
                $this->db->beginTransaction();
                try {
                    // Generate a unique expected amount candidate
                    $expected = $this->generateUniqueAmount($network, $requestedAmount);
                    $expiresAt = \date('Y-m-d H:i:s', \time() + ($expireMinutes * 60));

                    $id = $this->intentModel->create([
                        'user_id' => $userId,
                        'network' => $network,
                        'requested_amount' => $requestedAmount,
                        'expected_amount' => $expected,
                        'to_wallet' => $toWallet,
                        'expires_at' => $expiresAt,
                        'status' => 'open',
                        'ip_address' => $cleanIp,
                        'user_agent' => $userAgent,
                        'created_at' => \date('Y-m-d H:i:s'),
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ]);

                    $this->db->commit();
                    break; // Success! Exit retry loop
                } catch (\Exception $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }

                    // If it is a duplicate entry exception (23000), let's retry
                    if ($e instanceof \PDOException && ($e->getCode() === '23000' || \str_contains($e->getMessage(), 'Duplicate entry'))) {
                        $attempt++;
                        if ($attempt >= $maxRetryIntents) {
                            throw new \RuntimeException("امکان تولید درخواست واریز منحصر به فرد به دلیل ترافیک بالا در این لحظه وجود ندارد. لطفا مجددا تلاش کنید.");
                        }
                        continue; // Retry with next attempt
                    }
                    throw $e; // Rethrow other exceptions
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('crypto.intent.create.failed', [
                'channel' => 'crypto',
                'user_id' => $userId,
                'network' => $network,
                'requested_amount' => $requestedAmount,
                'error' => $e->getMessage(),
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['success' => false, 'message' => ($e instanceof \RuntimeException) ? $e->getMessage() : 'خطای سیستمی در ساخت درخواست'];
        }

        $this->logger->info('crypto.intent.created', [
            'user_id' => $userId,
            'intent_id' => $id,
            'network' => $network,
            'requested_amount' => $requestedAmount,
            'expected_amount' => $expected,
            'expires_at' => $expiresAt
        ]);

        return [
            'success' => true,
            'message' => 'Intent ساخته شد',
            'intent_id' => (int) $id,
            'network' => $network,
            'requested_amount' => $requestedAmount,
            'expected_amount' => $expected,
            'to_wallet' => $toWallet,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Create a new crypto deposit (direct store from user)
     */
    public function createDeposit(int $userId, array $data): array
    {
        try {
            return $this->transaction(function() use ($userId, $data) {
                // Pessimistic lock check on tx_hash and network to prevent race condition and cross-network bypass (C-01 & C-06, M-02)
                $existingDeposit = $this->depositModel->findByHashAndNetworkForUpdate($data['tx_hash'], $data['network']);
                if ($existingDeposit) {
                    throw new \RuntimeException('این هش تراکنش قبلاً ثبت شده است');
                }

                // دریافت آدرس کیف پول مقصد
                $walletAddress = $data['network'] === 'bnb20' 
                    ? $this->settingService->get('site_usdt_bnb20_address')
                    : $this->settingService->get('site_usdt_trc20_address');

                if (!$walletAddress) {
                    throw new \RuntimeException('آدرس کیف پول این شبکه تنظیم نشده است');
                }

                $data['user_id'] = $userId;
                $data['wallet_address'] = $walletAddress;
                $data['verification_status'] = 'pending';
                
                // Set auto_check_deadline (30 mins from now in default timezone)
                $minutes = (int) ($this->settingService->get('crypto_intent_expire_minutes') ?: \App\Constants\CryptoConstants::DEFAULT_INTENT_EXPIRE_MINUTES);
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
        if ($adminId <= 0 || $depositId <= 0) {
            return ['success' => false, 'message' => 'شناسه نامعتبر است'];
        }

        try {
            $this->db->beginTransaction();

            $deposit = $this->depositModel->find($depositId);
            if (!$deposit) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'واریز یافت نشد'];
            }

            $currentStatus = $deposit->verification_status ?? 'pending';
            if (!$this->stateMachine->canTransition('crypto_deposit', $currentStatus, 'verified')) {
                $this->db->rollBack();
                return ['success' => false, 'message' => "تغییر وضعیت از وضعیت فعلی ({$currentStatus}) به verified مجاز نیست"];
            }

            $payload = [
                'user_id' => (int)$deposit->user_id,
                'amount' => (string)$deposit->amount,
                'currency' => 'usdt',
                'metadata' => [
                    'type' => 'crypto_deposit',
                    'gateway' => 'usdt_' . $deposit->network,
                    'gateway_transaction_id' => $deposit->tx_hash,
                    'description' => 'واریز USDT - ' . strtoupper((string)$deposit->network),
                    'network' => $deposit->network,
                    'tx_hash' => $deposit->tx_hash,
                    'deposit_id' => $depositId,
                    'approved_by' => $adminId,
                ],
            ];

            if ($this->outbox) {
                $ok = $this->outbox->record('crypto_deposit', $depositId, 'wallet.deposit.requested', $payload);
                if (!$ok) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'خطا در ثبت رکورد خروجی برای واریز کریپتو'];
                }
            } else {
                $depositResult = $this->wallet->deposit(
                    (int)$deposit->user_id,
                    (string)$deposit->amount,
                    'usdt',
                    [
                        'type' => 'crypto_deposit',
                        'gateway' => 'usdt_' . $deposit->network,
                        'gateway_transaction_id' => $deposit->tx_hash,
                        'description' => 'واریز USDT - ' . strtoupper((string)$deposit->network),
                        'network' => $deposit->network,
                        'tx_hash' => $deposit->tx_hash,
                        'deposit_id' => $depositId,
                        'approved_by' => $adminId,
                    ]
                );

                if (!$depositResult['success']) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'خطا در واریز به کیف پول'];
                }
            }

            // Use nesting-safe updateStatus()
            $this->depositModel->updateStatus(
                $depositId,
                'verified',
                null,
                null,
                $adminId,
                isset($depositResult['transaction_id']) ? (string)$depositResult['transaction_id'] : null
            );

            // Log secure status transition audit
            $this->logger->info('crypto.deposit.status_transition', [
                'deposit_id' => $depositId,
                'user_id' => $deposit->user_id,
                'from_status' => $currentStatus,
                'to_status' => 'verified',
                'operator_id' => $adminId,
                'triggered_by' => 'admin_approve',
            ]);

            $this->recordNotificationOutbox($depositId, 'notification.crypto_deposit_approved', 'send', [
                (int)$deposit->user_id,
                'deposit',
                'واریز کریپتو تأیید شد',
                'تراکنش واریز شما در شبکه ' . strtoupper((string)$deposit->network) . ' به مبلغ ' . $deposit->amount . ' USDT تأیید شد.',
                [
                    'amount' => $deposit->amount,
                    'network' => $deposit->network,
                    'tx_hash' => $deposit->tx_hash,
                ]
            ]);

            $this->db->commit();

            $this->eventDispatcher->dispatch('crypto.deposit.confirmed', [
                'deposit_id' => $depositId,
                'user_id' => (int)$deposit->user_id,
                'amount' => $deposit->amount,
                'network' => $deposit->network,
                'tx_hash' => $deposit->tx_hash,
                'admin_id' => $adminId,
                'auto_verified' => false
            ]);

            // MED-27: Fix severe application crash (TypeError) by ensuring correct string inputs to notification engines
            if (!$this->outbox) {
                try {
                    $this->notifier->send(
                    (int)$deposit->user_id,
                    'deposit', // Valid mapping
                    'واریز کریپتو تأیید شد',
                    'تراکنش واریز شما در شبکه ' . strtoupper((string)$deposit->network) . ' به مبلغ ' . $deposit->amount . ' USDT تأیید شد.',
                    [
                        'amount' => $deposit->amount,
                        'network' => $deposit->network,
                        'tx_hash' => $deposit->tx_hash,
                    ]
                );
                } catch (\Throwable $notifErr) {
                    $this->logger->error('crypto.deposit.approve.notification_failed', [
                        'deposit_id' => $depositId,
                        'error' => $notifErr->getMessage()
                    ]);
                }
            }

            $this->logger->info('crypto.deposit.approved', [
                'deposit_id' => $depositId,
                'user_id' => $deposit->user_id,
                'admin_id' => $adminId,
                'amount' => $deposit->amount,
                'network' => $deposit->network,
            ]);

            return ['success' => true, 'message' => 'واریز تأیید شد'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('crypto.deposit.approve.failed', [
                'deposit_id' => $depositId,
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی'];
        }
    }

    /**
     * Reject a crypto deposit (admin action)
     * Enforces strict database transaction management and correct lock ordering to prevent race conditions (M-07, H-08)
     */
    public function reject(int $adminId, int $depositId, string $reason): array
    {
        $reason = trim(mb_substr($reason, 0, 500));
        if ($adminId <= 0 || $depositId <= 0 || $reason === '') {
            return ['success' => false, 'message' => 'شناسه یا دلیل رد نامعتبر است'];
        }

        $this->db->beginTransaction();
        try {
            $deposit = $this->depositModel->find($depositId);
            if (!$deposit) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'واریز یافت نشد'];
            }

            // 1. Lock Wallet row first to prevent deadlock and establish lock order hierarchy
            $this->db->prepare("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE")
                ->execute([(int)$deposit->user_id]);

            // 2. Lock crypto deposit row
            $stmt = $this->db->prepare("SELECT verification_status FROM crypto_deposits WHERE id = ? FOR UPDATE");
            $stmt->execute([$depositId]);
            $lockedStatus = $stmt->fetchColumn();

            if (!$lockedStatus) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'واریز یافت نشد'];
            }

            if (!$this->stateMachine->canTransition('crypto_deposit', $lockedStatus, 'rejected')) {
                $this->db->rollBack();
                return ['success' => false, 'message' => "تغییر وضعیت از وضعیت فعلی ({$lockedStatus}) به rejected مجاز نیست"];
            }

            // Update status using updateStatus()
            $this->depositModel->updateStatus(
                $depositId,
                'rejected',
                null,
                $reason,
                $adminId,
                null
            );

            // Audit Log (M-12)
            $this->logger->info('crypto.deposit.status_transition', [
                'deposit_id' => $depositId,
                'user_id' => $deposit->user_id,
                'from_status' => $lockedStatus,
                'to_status' => 'rejected',
                'operator_id' => $adminId,
                'triggered_by' => 'admin_reject',
                'reason' => $reason,
            ]);

            $this->recordNotificationOutbox($depositId, 'notification.crypto_deposit_rejected', 'send', [
                (int)$deposit->user_id,
                'deposit',
                'واریز کریپتو رد شد',
                'درخواست واریز کریپتو شما به مبلغ ' . $deposit->amount . ' USDT رد شد. دلیل: ' . $reason,
                [
                    'amount' => $deposit->amount,
                    'network' => $deposit->network,
                    'tx_hash' => $deposit->tx_hash,
                    'reason' => $reason,
                ]
            ]);

            $this->db->commit();

            // Notify user (M-11)
            if (!$this->outbox) {
                try {
                    $this->notifier->send(
                    (int)$deposit->user_id,
                    'deposit',
                    'واریز کریپتو رد شد',
                    'درخواست واریز کریپتو شما به مبلغ ' . $deposit->amount . ' USDT رد شد. دلیل: ' . $reason,
                    [
                        'amount' => $deposit->amount,
                        'network' => $deposit->network,
                        'tx_hash' => $deposit->tx_hash,
                        'reason' => $reason,
                    ]
                );
                } catch (\Throwable $notifErr) {
                    $this->logger->error('crypto.deposit.reject.notification_failed', [
                        'deposit_id' => $depositId,
                        'error' => $notifErr->getMessage()
                    ]);
                }
            }

            return ['success' => true, 'message' => 'واریز رد شد'];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('crypto.deposit.reject.failed', [
                'deposit_id' => $depositId,
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی'];
        }
    }

    /**
     * Try auto-verification of a crypto deposit
     */
    /**
     * Section 8.2 — idempotent at the API-call boundary.
     * The verification logic itself stays unchanged. Multiple concurrent
     * verify requests for the same depositId return the same cached
     * outcome instead of racing on the underlying state machine.
     */
    public function tryAutoVerify(int $depositId): array
    {
        if ($depositId <= 0) {
            return ['auto' => false, 'message' => 'شناسه واریز نامعتبر است'];
        }
        return $this->idempotent(
            'crypto_deposit.auto_verify',
            (int)($depositId),
            ['deposit_id' => $depositId],
            fn() => $this->tryAutoVerifyInternal($depositId)
        );
    }

    private function tryAutoVerifyInternal(int $depositId): array
    {
        if ($depositId <= 0) {
            return ['auto' => false, 'message' => 'شناسه واریز نامعتبر است'];
        }

        $d = $this->depositModel->find($depositId);
        if (!$d) {
            $this->logger->error('crypto.verify.deposit_not_found', [
                'deposit_id' => $depositId
            ]);
            return ['auto' => false, 'message' => 'واریز یافت نشد'];
        }

        if (!$this->isAllowedNetwork((string)$d->network) || !$this->isValidTxHash((string)$d->tx_hash)) {
            $this->logger->warning('crypto.verify.invalid_payload', [
                'deposit_id' => $depositId,
                'network' => $d->network ?? null,
                'tx_hash' => $d->tx_hash ?? null,
            ]);
            return $this->moveToManualReview($depositId, 'داده‌های تراکنش برای بررسی خودکار معتبر نیست');
        }

        // Limit the number of auto check attempts to 10 (M-08)
        if ((int)$d->auto_check_attempts >= 10) {
            return $this->moveToManualReview($depositId, 'تعداد تلاشهای بررسی بیش از حد مجاز');
        }

        $currentStatus = $d->verification_status ?? 'pending';
        // If current state is terminal (cannot transition to anything)
        if ($this->stateMachine->isTerminalState('crypto_deposit', $currentStatus)) {
            $this->logger->warning('crypto.verify.terminal_state', [
                'deposit_id' => $depositId,
                'status' => $currentStatus
            ]);
            return ['auto' => false, 'message' => 'این تراکنش قبلاً نهایی شده است'];
        }

        $this->logger->info('crypto.verify.started', [
            'deposit_id' => $depositId,
            'user_id' => $d->user_id,
            'network' => $d->network,
            'amount' => $d->amount,
            'tx_hash' => $d->tx_hash
        ]);

        // H-05: Use system default timezone matching when checking deadline
        if ($d->auto_check_deadline) {
            $deadline = new \DateTime($d->auto_check_deadline);
            $now = new \DateTime();

            if ($deadline->getTimestamp() < $now->getTimestamp()) {
                // اگر هنوز pending است => reject timeout
                if ($d->verification_status === 'pending') {
                    if ($this->stateMachine->canTransition('crypto_deposit', $currentStatus, 'rejected')) {
                        $this->depositModel->updateStatus($depositId, 'rejected', null, 'مهلت بررسی خودکار (۳۰ دقیقه) تمام شد');

                        // Audit Log
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

                    return ['auto' => false, 'message' => 'رد شد (پایان مهلت ۳۰ دقیقه)'];
                }
            }
        }

        // افزایش attempts
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
            // M-03: Structured error logging with complete details and trace
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
            return $this->moveToManualReview($depositId, 'خطا در اتصال به Explorer');
        }

        if (($result['status'] ?? '') === 'verified') {
            $this->db->beginTransaction();
            try {
                // Issue 4: Lock the record to prevent race conditions during auto-verify (C-02)
                $stmt = $this->db->prepare("SELECT verification_status FROM crypto_deposits WHERE id = ? FOR UPDATE");
                $stmt->execute([$depositId]);
                $lockedStatus = $stmt->fetchColumn();

                if (!$lockedStatus) {
                    $this->db->rollBack();
                    return ['auto' => false, 'message' => 'تراکنش یافت نشد'];
                }

                if (in_array($lockedStatus, ['verified', 'auto_verified'])) {
                    $this->db->rollBack();
                    $this->logger->warning('crypto.verify.already_verified', ['deposit_id' => $depositId]);
                    return ['auto' => true, 'message' => 'این تراکنش قبلاً تأیید شده است'];
                }

                // Verify transition is permitted from current status (C-02, C-13)
                if (!$this->stateMachine->canTransition('crypto_deposit', $lockedStatus, 'auto_verified')) {
                    $this->db->rollBack();
                    return ['auto' => false, 'message' => "تغییر وضعیت به auto_verified از وضعیت فعلی ({$lockedStatus}) مجاز نیست"];
                }

                // C-02: Lock both the deposit AND the wallet records inside the transaction before update
                $stmtWallet = $this->db->prepare("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE");
                $stmtWallet->execute([(int)$d->user_id]);

                // Use depositInTransaction to ensure atomic updates with the lock
                // H-04: Inject a strict, unique idempotency key to prevent double crediting on API or retry crashes
                $okResult = $this->wallet->depositInTransaction((int)$d->user_id, (string)$d->amount, 'usdt', [
                    'type' => 'crypto_deposit',
                    'deposit_id' => $depositId,
                    'network' => (string)$d->network,
                    'tx_hash' => (string)$d->tx_hash,
                    'idempotency_key' => 'crypto_deposit_' . $depositId,
                ]);

                if ($okResult['success'] ?? false) {
                    // Update status using updateStatus()
                    $this->depositModel->updateStatus(
                        $depositId,
                        'auto_verified',
                        $result['details'] ?? null,
                        null,
                        null,
                        $okResult['transaction_id'] ?? null
                    );

                    // Audit Log
                    $this->logger->info('crypto.deposit.status_transition', [
                        'deposit_id' => $depositId,
                        'user_id' => $d->user_id,
                        'from_status' => $lockedStatus,
                        'to_status' => 'auto_verified',
                        'operator_id' => null,
                        'triggered_by' => 'auto_verify_success',
                    ]);

                    // ✅ **تطبیق crypto deposit با blockchain و wallet** (H-02)
                    $reconciliation = $this->reconciliationService->reconcilePayment([
                        'transaction_id' => (string)$d->tx_hash,
                        'reference_id' => 'crypto_deposit_' . $depositId,
                        'user_id' => (int)$d->user_id,
                        'amount' => (float)$d->amount,
                        'currency' => 'usdt',
                        'status' => 'success',
                        'gateway' => 'crypto_' . strtolower((string)$d->network),
                        'description' => "تطبیق crypto deposit - Network: {$d->network}, Tx: {$d->tx_hash}",
                        'timestamp' => time(),
                        'is_internal' => true,
                    ]);

                    if (!$reconciliation['success']) {
                        throw new \RuntimeException('خطا در تطبیق مالی تراکنش: ' . ($reconciliation['message'] ?? 'Unknown error'));
                    }

                    $this->recordNotificationOutbox($depositId, 'notification.crypto_deposit_auto_verified', 'send', [
                        (int)$d->user_id,
                        'deposit',
                        'واریز خودکار کریپتو تأیید شد',
                        'تراکنش واریز خودکار شما در شبکه ' . strtoupper((string)$d->network) . ' به مبلغ ' . $d->amount . ' USDT با موفقیت تأیید و به کیف پول شما واریز شد.',
                        [
                            'amount' => $d->amount,
                            'network' => $d->network,
                            'tx_hash' => $d->tx_hash,
                        ]
                    ]);

                    $this->db->commit();

                    $this->eventDispatcher->dispatch('crypto.deposit.confirmed', [
                        'deposit_id' => $depositId,
                        'user_id' => (int)$d->user_id,
                        'amount' => $d->amount,
                        'network' => $d->network,
                        'tx_hash' => $d->tx_hash,
                        'admin_id' => null,
                        'auto_verified' => true
                    ]);

                    // Notify user on auto-verify success (H-03/H-06)
                    if (!$this->outbox) {
                        try {
                            $this->notifier->send(
                            (int)$d->user_id,
                            'deposit',
                            'واریز خودکار کریپتو تأیید شد',
                            'تراکنش واریز خودکار شما در شبکه ' . strtoupper((string)$d->network) . ' به مبلغ ' . $d->amount . ' USDT با موفقیت تأیید و به کیف پول شما واریز شد.',
                            [
                                'amount' => $d->amount,
                                'network' => $d->network,
                                'tx_hash' => $d->tx_hash,
                            ]
                        );
                        } catch (\Throwable $notifErr) {
                            $this->logger->error('crypto.verify.auto_success.notification_failed', [
                                'deposit_id' => $depositId,
                                'error' => $notifErr->getMessage()
                            ]);
                        }
                    }

                    if (!$reconciliation['success']) {
                        // L-05: Proactively alert administrators of a critical reconciliation failure
                        try {
                            $this->notifier->sendToAdmins(
                                'crypto_reconciliation_failure',
                                'خطای تطبیق واریز کریپتو',
                                "سیستم قادر به تطبیق تراکنش کریپتو با شناسه واریز {$depositId} و کاربر {$d->user_id} به مبلغ {$d->amount} نشد.",
                                [
                                    'deposit_id' => $depositId,
                                    'user_id' => $d->user_id,
                                    'network' => $d->network,
                                    'tx_hash' => $d->tx_hash,
                                    'message' => $reconciliation['message'] ?? 'Unknown error'
                                ]
                            );
                        } catch (\Throwable $notifErr) {
                            $this->logger->error('crypto.verify.reconciliation_notification_failed', [
                                'error' => $notifErr->getMessage()
                            ]);
                        }

                        $this->logger->critical('crypto.verify.reconciliation_failed', [
                            'deposit_id' => $depositId,
                            'user_id' => $d->user_id,
                            'amount' => $d->amount,
                            'network' => $d->network,
                            'message' => $reconciliation['message'] ?? 'Unknown reconciliation error',
                        ]);
                    }

                    $this->logger->info('crypto.verify.auto_success', [
                        'deposit_id' => $depositId,
                        'user_id' => $d->user_id,
                        'amount' => $d->amount,
                        'network' => $d->network,
                        'tx_hash' => $d->tx_hash
                    ]);

                    return ['auto' => true, 'message' => 'تأیید خودکار موفق'];
                } else {
                    $this->db->rollBack();
                    $this->logger->error('crypto.verify.wallet_deposit_failed', [
                        'deposit_id' => $depositId,
                        'user_id' => $d->user_id,
                        'reason' => $okResult['message'] ?? 'Unknown wallet error'
                    ]);
                    return $this->moveToManualReview($depositId, 'خطا در واریز به کیف پول: ' . ($okResult['message'] ?? 'نامشخص'));
                }
            } catch (\Exception $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->logger->error('crypto.verify.auto_deposit_failed', [
                    'deposit_id' => $depositId,
                    'user_id' => $d->user_id,
                    'error' => $e->getMessage()
                ]);
                return $this->moveToManualReview($depositId, 'خطا در واریز خودکار: ' . $e->getMessage());
            }
        } elseif (($result['status'] ?? '') === 'mismatch') {
            return $this->moveToManualReview($depositId, $result['reason'] ?? 'عدم تطابق داده‌ها');
        } elseif (($result['status'] ?? '') === 'pending') {
            // Transaction found but confirmations count is insufficient - keep as pending for next cron check
            return ['auto' => false, 'message' => $result['reason'] ?? 'تراکنش در انتظار تایید شبکه'];
        } else {
            // Temporary explorer error or circuit breaker active - keep as pending to retry via cron until max attempts (10)
            if ((int)$d->auto_check_attempts >= 10) {
                return $this->moveToManualReview($depositId, 'عدم موفقیت در استعلام پس از تلاشهای مکرر: ' . ($result['reason'] ?? 'بررسی خودکار ناموفق'));
            }
            return ['auto' => false, 'message' => 'خطای موقت در اتصال به شبکه رمزارز: ' . ($result['reason'] ?? 'ارتباط با Explorer قطع است')];
        }
    }

    /**
     * Move deposit to manual review
     */
    private function moveToManualReview(int $depositId, string $reason): array
    {
        $d = $this->depositModel->find($depositId);
        if (!$d) {
            return ['auto' => false, 'message' => 'واریز یافت نشد'];
        }

        $currentStatus = $d->verification_status ?? 'pending';

        if ($this->stateMachine->canTransition('crypto_deposit', $currentStatus, 'manual_review')) {
            $this->depositModel->updateStatus($depositId, 'manual_review', null, $reason);

            // Audit Log
            $this->logger->info('crypto.deposit.status_transition', [
                'deposit_id' => $depositId,
                'user_id' => $d->user_id,
                'from_status' => $currentStatus,
                'to_status' => 'manual_review',
                'operator_id' => null,
                'triggered_by' => 'auto_verify_fallback',
            ]);
        }

        return ['auto' => false, 'message' => 'ارسال به بررسی دستی: ' . $reason];
    }

    private function validateCryptoIntentInput(int $userId, string $network, float $requestedAmount): ?array
    {
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'کاربر نامعتبر است'];
        }
        if (!$this->isAllowedNetwork($network)) {
            return ['success' => false, 'message' => 'شبکه رمزارز پشتیبانی نمی‌شود'];
        }
        if (!is_finite($requestedAmount) || $requestedAmount <= 0) {
            return ['success' => false, 'message' => 'مبلغ درخواست نامعتبر است'];
        }
        if ($requestedAmount > 1000000) {
            return ['success' => false, 'message' => 'مبلغ درخواست بیش از حد مجاز است'];
        }
        return null;
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
            'TRC20' => $this->settingService->get('site_wallet_trc20'),
            'BNB20' => $this->settingService->get('site_wallet_bnb20'),
            'ERC20' => $this->settingService->get('site_wallet_erc20'),
            'TON' => $this->settingService->get('site_wallet_ton'),
            'SOL' => $this->settingService->get('site_wallet_sol'),
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
        $stmt = $this->db->prepare("UPDATE crypto_deposit_intents SET status = 'expired' WHERE status = 'open' AND expires_at < NOW()");
        $stmt->execute();
        $count = $stmt->rowCount();

        if ($count > 0) {
            $this->logger->info('crypto.intents.cleanup', ['expired_count' => $count]);
        }

        return $count;
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
}


