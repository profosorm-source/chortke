<?php

declare(strict_types=1);

namespace App\Services\CryptoDeposit;

use App\Services\Adapters\CryptoVerificationAdapter;
use App\Services\Notification\NotificationService;
use App\Services\WalletService;
use App\Services\SettingService;
use App\Services\ReconciliationService;
use App\Models\CryptoDepositIntent;
use App\Models\CryptoDeposit;
use Core\Database;
use App\Contracts\LoggerInterface;

class CryptoDepositService extends \App\Services\BaseService
{
    private Database $db;
    private CryptoDepositIntent $intentModel;
    private CryptoDeposit $depositModel;
    private NotificationService $notifier;
    private WalletService $wallet;
    private CryptoVerificationAdapter $verifier;
    private SettingService $settingService;
    private ReconciliationService $reconciliationService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;

    public function __construct(
        Database $db,
        WalletService $walletService,
        NotificationService $notificationService,
        CryptoDepositIntent $intentModel,
        CryptoDeposit $depositModel,
        LoggerInterface $logger,
        CryptoVerificationAdapter $verifier,
        SettingService $settingService,
        ReconciliationService $reconciliationService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard
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
            'ip'          => $ipAddress,
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

        $expireMinutes = (int) $this->settingService->get('crypto_intent_expire_minutes', 30);

        $open = $this->intentModel->getOpenIntentForUser($userId);
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

        $expected = $this->generateUniqueAmount($network, $requestedAmount);
        $expiresAt = \date('Y-m-d H:i:s', \time() + ($expireMinutes * 60));

        try {
            $id = $this->intentModel->create([
                'user_id' => $userId,
                'network' => $network,
                'requested_amount' => $requestedAmount,
                'expected_amount' => $expected,
                'to_wallet' => $toWallet,
                'expires_at' => $expiresAt,
                'status' => 'open',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => \date('Y-m-d H:i:s'),
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
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
            return ['success' => false, 'message' => 'خطای سیستمی در ساخت درخواست'];
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
     * Approve a crypto deposit (admin action)
     */
    public function approve(int $adminId, int $depositId): array
    {
        try {
            $this->db->beginTransaction();

            $deposit = $this->depositModel->find($depositId);
            if (!$deposit) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'واریز یافت نشد'];
            }

            if (($deposit->verification_status ?? null) === 'verified') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'این واریز قبلاً تأیید شده است'];
            }

            $depositResult = $this->wallet->deposit(
                (int)$deposit->user_id,
                (float)$deposit->amount,
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

            $this->depositModel->update($depositId, [
                'verification_status' => 'verified',
                'reviewed_at' => \date('Y-m-d H:i:s'),
                'reviewed_by' => $adminId,
                'wallet_transaction_id' => $depositResult['transaction_id'] ?? null,
            ]);

            $this->db->commit();

            // Notify user
            $this->notifier->send($deposit->user_id, 'crypto_deposit_approved', [
                'amount' => $deposit->amount,
                'network' => $deposit->network,
                'tx_hash' => $deposit->tx_hash,
            ]);

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
     * Try auto-verification of a crypto deposit
     */
    public function tryAutoVerify(int $depositId): array
    {
        $d = $this->depositModel->find($depositId);
        if (!$d) {
            $this->logger->error('crypto.verify.deposit_not_found', [
                'deposit_id' => $depositId
            ]);
            return ['auto' => false, 'message' => 'واریز یافت نشد'];
        }

        $this->logger->info('crypto.verify.started', [
            'deposit_id' => $depositId,
            'user_id' => $d->user_id,
            'network' => $d->network,
            'amount' => $d->amount,
            'tx_hash' => $d->tx_hash
        ]);

        // فقط داخل پنجره 30 دقیقه
        if ($d->auto_check_deadline && \strtotime((string)$d->auto_check_deadline) < \time()) {
            // اگر هنوز pending است => reject timeout
            if ($d->verification_status === 'pending') {
                $this->depositModel->update($depositId, [
                    'verification_status' => 'rejected',
                    'mismatch_reason' => 'مهلت بررسی خودکار (۳۰ دقیقه) تمام شد',
                    'risk_score' => 20,
                    'reviewed_at' => \date('Y-m-d H:i:s'),
                ]);

                $this->logger->warning('crypto.verify.timeout', [
                    'deposit_id' => $depositId,
                    'user_id' => $d->user_id,
                    'deadline' => $d->auto_check_deadline
                ]);

                return ['auto' => false, 'message' => 'رد شد (پایان مهلت ۳۰ دقیقه)'];
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
            $this->logger->error('legacy.log_error_advanced', [
                'args' => [
                    'خطا در تأیید خودکار تراکنش کریپتو',
                    'ERROR',
                    $e,
                    ['deposit_id' => $depositId, 'user_id' => $d->user_id, 'tx_hash' => $d->tx_hash]
                ]
            ]);
            return $this->moveToManualReview($depositId, 'خطا در اتصال به Explorer');
        }

        if (($result['status'] ?? '') === 'verified') {
            try {
                $ok = $this->wallet->deposit((int)$d->user_id, (float)$d->amount, 'usdt', [
                    'type' => 'crypto_deposit',
                    'deposit_id' => $depositId,
                    'network' => (string)$d->network,
                    'tx_hash' => (string)$d->tx_hash,
                ]);

                if ($ok) {
                    $this->depositModel->update($depositId, [
                        'verification_status' => 'verified',
                        'reviewed_at' => \date('Y-m-d H:i:s'),
                        'auto_verified' => 1,
                    ]);

                    // ✅ **تطبیق crypto deposit با blockchain و wallet**
                    // تأیید: آیا crypto deposit واقعاً به wallet رسید؟
                    $reconciliation = $this->reconciliationService->reconcilePayment([
                        'transaction_id' => (string)$d->tx_hash,
                        'reference_id' => 'crypto_deposit_' . $depositId,
                        'user_id' => (int)$d->user_id,
                        'amount' => (float)$d->amount,
                        'currency' => 'usdt',
                        'status' => 'success',
                        'gateway' => 'crypto_' . strtolower($d->network),
                        'description' => "تطبیق crypto deposit - Network: {$d->network}, Tx: {$d->tx_hash}",
                        'timestamp' => time(),
                    ]);

                    if (!$reconciliation['success']) {
                        $this->logger->warning('crypto.verify.reconciliation_failed', [
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
                    $this->logger->error('crypto.verify.wallet_deposit_failed', [
                        'deposit_id' => $depositId,
                        'user_id' => $d->user_id
                    ]);
                    return $this->moveToManualReview($depositId, 'خطا در واریز به کیف پول');
                }
            } catch (\Exception $e) {
                $this->logger->error('crypto.verify.auto_deposit_failed', [
                    'deposit_id' => $depositId,
                    'user_id' => $d->user_id,
                    'error' => $e->getMessage()
                ]);
                return $this->moveToManualReview($depositId, 'خطا در واریز خودکار');
            }
        } elseif (($result['status'] ?? '') === 'mismatch') {
            return $this->moveToManualReview($depositId, $result['reason'] ?? 'عدم تطابق داده‌ها');
        } else {
            // unavailable or error - move to manual review
            return $this->moveToManualReview($depositId, $result['reason'] ?? 'بررسی خودکار ناموفق');
        }
    }

    /**
     * Move deposit to manual review
     */
    private function moveToManualReview(int $depositId, string $reason): array
    {
        $this->depositModel->update($depositId, [
            'verification_status' => 'manual_review',
            'mismatch_reason' => $reason,
            'risk_score' => 50, // Higher risk for manual review
        ]);

        return ['auto' => false, 'message' => 'ارسال به بررسی دستی: ' . $reason];
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
        $maxAttempts = 10;
        $attempt = 0;
        do {
            // Add a wider random amount (0.00001 to 0.09999)
            $randomAddition = \mt_rand(1, 9999) / 100000;
            $expected = \round($requestedAmount + $randomAddition, 5);

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM crypto_deposit_intents WHERE network = ? AND expected_amount = ? AND status = 'open'");
            $stmt->execute([$network, $expected]);
            $count = (int)$stmt->fetchColumn();

            if ($count === 0) {
                return $expected;
            }
            $attempt++;
        } while ($attempt < $maxAttempts);

        // Fallback to a wider random value if we hit max attempts
        return \round($requestedAmount + (\mt_rand(10000, 99999) / 100000), 5);
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
        $query = $this->depositModel->query()
            ->selectRaw("crypto_deposits.id, crypto_deposits.amount, 'crypto' as type, crypto_deposits.verification_status as status, crypto_deposits.created_at, u.full_name, u.email")
            ->leftJoin('users as u', 'u.id', '=', 'crypto_deposits.user_id');

        $this->depositModel->applySearch($query, $term);

        if (!empty($term)) {
            $escaped = addcslashes(trim($term), '%_');
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
