<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Notification\NotificationService;
use App\Services\Payment\PaymentBaseService;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Models\Withdrawal;
use App\Models\WithdrawalLimit;
use App\Models\User;
use App\Models\BankCard;
use App\Services\SettingService;
use App\Services\BankCardService;
use App\Services\KYCService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Services\PerformanceOptimizationService;
use App\Services\ReconciliationService;
use App\Contracts\CurrencyServiceInterface;

class WithdrawalService extends PaymentBaseService
{
    private \App\Models\User         $userModel;
    private \App\Models\Transaction  $transactionModel;
    private \App\Models\BankCard     $bankCardModel;
    private Database                 $db;
    private BankCardService          $bankCardService;
    private KYCService               $kycService;
    private RiskDecisionService      $riskDecisionService;
    private Withdrawal               $model;
    private WithdrawalLimit          $limitModel;
    private SettingService           $settings;
    private WalletService            $wallet;
    private NotificationService      $notifier;
    private AuditTrail               $auditTrail;
    private PerformanceOptimizationService $performance;
    private StateMachineService      $stateMachine;
    private ReconciliationService    $reconciliation;
    private CurrencyServiceInterface $currencyService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;

    private const PROFILES_DEFAULT = [
        'no_kyc'        => ['daily'=>0,  'weekly'=>0,   'monthly'=>0,   'multiplier'=>0],
        'silver_kyc'    => ['daily'=>1,  'weekly'=>3,   'monthly'=>10,  'multiplier'=>1.0],
        'gold_kyc'      => ['daily'=>3,  'weekly'=>10,  'monthly'=>30,  'multiplier'=>2.0],
        'vip_kyc'       => ['daily'=>5,  'weekly'=>20,  'monthly'=>60,  'multiplier'=>5.0],
        'admin'         => ['daily'=>999,'weekly'=>9999,'monthly'=>99999,'multiplier'=>100.0],
    ];

    public function __construct(
        Database               $db,
        WalletService          $walletService,
        NotificationService    $notificationService,
        \App\Models\Withdrawal      $model,
        \App\Models\WithdrawalLimit $limitModel,
        SettingService              $settings,
        \App\Models\BankCard        $bankCardModel,
        \App\Services\BankCardService $bankCardService,
        \App\Services\AntiFraud\RiskDecisionService $riskDecisionService,
        \App\Services\KYCService          $kycService,
        \App\Models\Transaction     $transactionModel,
        \App\Models\User            $userModel,
        AuditTrail             $auditTrail,
        LoggerInterface        $logger,
        PerformanceOptimizationService $performance,
        StateMachineService    $stateMachine,
        ReconciliationService  $reconciliation,
        CurrencyServiceInterface $currencyService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard
    ) {
        parent::__construct($logger);
        $this->db               = $db;
        $this->model            = $model;
        $this->limitModel       = $limitModel;
        $this->settings         = $settings;
        $this->wallet           = $walletService;
        $this->notifier         = $notificationService;
        $this->bankCardModel    = $bankCardModel;
        $this->bankCardService  = $bankCardService;
        $this->riskDecisionService = $riskDecisionService;
        $this->kycService       = $kycService;
        $this->transactionModel = $transactionModel;
        $this->userModel        = $userModel;
        $this->auditTrail       = $auditTrail;
        $this->performance      = $performance;
        $this->stateMachine     = $stateMachine;
        $this->reconciliation   = $reconciliation;
        $this->currencyService  = $currencyService;
        $this->fraudGuard       = $fraudGuard;
    }

    public function requestFromUser(int $userId, array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $currency = (string)($payload['currency'] ?? 'irt');
        $bankCardId = (int)($payload['bank_card_id'] ?? 0);
        $requestId = (string)($payload['request_id'] ?? bin2hex(random_bytes(8)));
        $ip = (string)($payload['ip'] ?? '');
        $fingerprint = (string)($payload['fingerprint'] ?? '');

        try {
            if ($amount <= 0) {
                return ['success' => false, 'message' => 'مبلغ نامعتبر است'];
            }

            $minAmount = (float)($this->settings->get('withdrawal_min_amount', 10000));
            if ($amount < $minAmount) {
                return ['success' => false, 'message' => "حداقل مبلغ برداشت " . $this->currencyService->formatAmount($minAmount, $currency) . " است"];
            }

            if (!$this->kycService->isApproved($userId)) {
                return ['success' => false, 'message' => 'احراز هویت شما کامل نیست'];
            }

            // کارت
            $card = $this->bankCardService->findVerifiedCardForUser($userId, $bankCardId);
            if (!$card) {
                return ['success' => false, 'message' => 'کارت بانکی معتبر یافت نشد'];
            }

            // 🛡️ گیت ضدتقلب متمرکز برداشت (Velocity, ATO, Geolocation)
            $risk = $this->fraudGuard->checkAction($userId, 'withdrawal.create', [
                'amount'      => $amount,
                'currency'    => $currency,
                'ip'          => $ip,
                'fingerprint' => $fingerprint,
                'user_agent'  => get_user_agent()
            ]);

            if (!$risk['allowed']) {
                $this->logger->warning('withdrawal.blocked_by_fraud_guard', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'reason' => $risk['reason']
                ]);
                return ['success' => false, 'message' => 'درخواست برداشت به دلایل امنیتی مسدود شد. دلیل: ' . ($risk['reason'] === 'velocity_limit' ? 'تجاوز از محدودیت تعداد تراکنش' : $risk['reason'])];
            }

            // تکراری/pending
            if ($this->model->hasPending($userId)) {
                return ['success' => false, 'message' => 'شما یک درخواست در حال بررسی دارید'];
            }

            // موجودی
            $can = $this->wallet->canWithdraw($userId, $amount, $currency);
            if (empty($can['can_withdraw'])) {
                return ['success' => false, 'message' => $can['message'] ?? 'موجودی کافی نیست'];
            }

            $this->db->beginTransaction();

            // قفل پول و جلوگیری از Race condition
            $idempotencyKey = $this->uuid();
            $debit = $this->wallet->withdraw($userId, $amount, $currency, [
                'type' => 'withdrawal_request',
                'request_id' => $requestId,
                'ip' => $ip,
                'fingerprint' => $fingerprint,
                'idempotency_key' => $idempotencyKey,
                'bank_card_id' => $bankCardId,
            ]);

            if (empty($debit['success'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $debit['message'] ?? 'خطا در رزرو مبلغ برداشت'];
            }

            $withdrawalId = $this->model->create([
                'user_id' => $userId,
                'bank_card_id' => $bankCardId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'request_id' => $requestId,
                'ip_address' => $ip,
                'device_fingerprint' => $fingerprint,
                'transaction_id' => $debit['transaction_id'] ?? null,
            ]);

            if (!$withdrawalId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت درخواست برداشت'];
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'درخواست برداشت با موفقیت ثبت شد'];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('withdrawal.request.failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی رخ داد'];
        }
    }

    /**
     * تولید شناسه یکتا برای جلوگیری از اجرای دوبار تراکنش (Idempotency)
     */
    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * ثبت درخواست برداشت
     */
    public function create(int $userId, array $data): array
    {
        $user = $this->userModel->find($userId);
        if (!$user || $user->kyc_status !== 'verified') {
            return ['success' => false, 'message' => 'برای برداشت باید احراز هویت تأیید شده باشد'];
        }

        $currency = (string)($data['currency'] ?? 'IRT');
        if (!in_array($currency, ['IRT', 'USDT'], true)) {
            return ['success' => false, 'message' => 'ارز نامعتبر'];
        }

        // ممانعت از برداشت ریالی در صورت فعال بودن حالت تتر-تنها
        if (strtoupper($currency) === 'IRT' && !$this->currencyService->isIRT()) {
            return ['success' => false, 'message' => 'برداشت ریالی در وضعیت فعلی سیستم مسدود است. لطفاً از برداشت تتر استفاده کنید'];
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'مبلغ نامعتبر'];
        }

        // H10: اعتبارسنجی سقف‌های برداشت به داخل بلاک قفل تراکنشی منتقل شد.

        // 🛡️ گیت ضدتقلب متمرکز برداشت (Velocity, ATO, Geolocation)
        $risk = $this->fraudGuard->checkAction($userId, 'withdrawal.create', [
            'amount'      => $amount,
            'currency'    => $currency,
            'ip'          => get_client_ip(),
            'user_agent'  => get_user_agent()
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('withdrawal.blocked_by_fraud_guard', [
                'user_id' => $userId,
                'amount'  => $amount,
                'reason'  => $risk['reason']
            ]);
            return ['success' => false, 'message' => 'برداشت وجه به دلایل امنیتی متوقف شد. دلیل: ' . ($risk['reason'] === 'velocity_limit' ? 'تجاوز از سقف برداشت امن' : $risk['reason'])];
        }

        // 🔒 شروع بلاک تراکنش سراسری جهت اعمال قفل انحصاری و ممانعت از رفتارهای مخرب موازی (Race Condition)
        $this->db->beginTransaction();

        try {
            // اعمال قفل بدبینانه روی سطر کاربر جهت سریالیزه کردن درخواست‌های مالی موازی
            $userLock = $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId])->fetch();
            if (!$userLock) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'کاربر یافت نشد'];
            }

            // ۱. بررسی محدودیت‌های برداشت (انتقال به درون قفل تراکنشی)
            $limitCheck = $this->check($userId, $amount, $currency);
            if (!$limitCheck['allowed']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $limitCheck['reason']];
            }

            // ۲. بررسی عدم وجود درخواست معلق فعلی با قفل تراکنشی جهت ممانعت قطعی از ثبت همزمان
            $pending = $this->db->query(
                "SELECT id FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'processing') LIMIT 1 FOR UPDATE",
                [$userId]
            )->fetch();
            
            if ($pending) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'شما یک برداشت در حال بررسی دارید'];
            }

            $min = (float)$this->settings->get(
                $currency === 'IRT' ? 'min_withdrawal_irt' : 'min_withdrawal_usdt',
                $currency === 'IRT' ? 50000 : 10
            );
            $max = (float)$this->settings->get(
                $currency === 'IRT' ? 'max_withdrawal_irt' : 'max_withdrawal_usdt',
                $currency === 'IRT' ? 50000000 : 100000
            );

            if ($amount < $min) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'کمتر از حداقل برداشت (' . $this->currencyService->formatAmount($min, $currency) . ') است'];
            }
            if ($amount > $max) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'بیشتر از حداکثر برداشت (' . $this->currencyService->formatAmount($max, $currency) . ') است'];
            }

            $feePercent = (float)$this->settings->get(
                $currency === 'IRT' ? 'withdrawal_fee_irt' : 'withdrawal_fee_usdt',
                0
            );
            $fee   = ($amount * $feePercent) / 100;
            $final = $amount - $fee;

            $availableApprox = $this->wallet->getBalance($userId, strtolower($currency));
            if ($availableApprox < $amount) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست'];
            }

            $withdrawalData = [
                'bank_card_id'   => null,
                'crypto_wallet'  => null,
                'crypto_network' => null,
            ];

            if ($currency === 'IRT') {
                $bankCardId = (int)($data['bank_card_id'] ?? $data['card_id'] ?? 0);
                $card = $this->bankCardModel
                    ->where('id', $bankCardId)
                    ->where('user_id', $userId)
                    ->where('status', 'verified')
                    ->where('deleted_at', null)
                    ->first();

                if (!$card) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'کارت بانکی مقصد نامعتبر یا تأیید نشده است'];
                }
                $withdrawalData['bank_card_id'] = $bankCardId;
            } else {
                $net  = (string)($data['crypto_network'] ?? '');
                $addr = trim((string)($data['crypto_wallet'] ?? ''));

                if (!in_array($net, ['BNB20', 'TRC20', 'ERC20', 'TON', 'SOL'], true)) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'شبکه نامعتبر'];
                }
                if ($addr === '' || strlen($addr) < 10) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'آدرس ولت نامعتبر'];
                }

                $withdrawalData['crypto_network'] = $net;
                $withdrawalData['crypto_wallet']  = $addr;
            }

            $idempotencyKey = $this->uuid();

            // بررسی idempotency تحت تراکنش
            $existing = $this->model->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $this->db->rollBack();
                return [
                    'success' => true,
                    'withdrawal_id' => (int)$existing->id,
                    'message' => 'درخواست برداشت قبلاً ثبت شده است'
                ];
            }
            $w = $this->wallet->withdraw(
                $userId,
                $amount,
                strtolower($currency),
                [
                    'type'            => 'withdrawal_request',
                    'description'     => 'درخواست برداشت',
                    'idempotency_key' => $idempotencyKey,
                    'fee'             => $fee,
                    'final_amount'    => $final,
                ]
            );

            if (!$w['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $w['message'] ?? 'خطا در ثبت برداشت'];
            }

            $idObj = $this->model->create(array_merge([
                'user_id'          => $userId,
                'currency'         => $currency,
                'amount'           => $amount,
                'fee'              => $fee,
                'final_amount'     => $final,
                'user_description' => $data['user_description'] ?? null,
                'status'           => 'pending',
                'idempotency_key'  => $idempotencyKey,
                'transaction_id'   => $w['transaction_id'] ?? null,
                'ip_address'       => get_client_ip(),
                'user_agent'       => get_user_agent(),
            ], $withdrawalData));

            if (!$idObj) {
                // اگر ایجاد رکورد withdrawal شکست خورد، عملیات والت را برگردانیم
                $this->wallet->cancelWithdrawal($userId, $amount, strtolower($currency), $w['transaction_id'] ?? null);
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت درخواست برداشت'];
            }

            $id = (int)$idObj->id;

            $this->increaseDailyLimit($userId);

            $this->auditTrail->record('withdrawal.requested', $userId, [
                'withdrawal_id' => (int)$id,
                'amount'        => $amount,
                'currency'      => $currency,
                'fee'           => $fee,
                'final_amount'  => $final,
                'method'        => $currency === 'IRT' ? 'bank_card' : 'crypto',
            ]);

            $this->db->commit();

            return [
                'success'       => true,
                'withdrawal_id' => (int)$id,
                'message'       => 'درخواست برداشت ثبت شد',
            ];

        } catch (\Exception $e) {
            // در صورت خطا، تراکنش را rollback کنیم
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            // اگر والت عملیات انجام شده، آن را برگردانیم
            try {
                $this->wallet->cancelWithdrawal($userId, $amount, strtolower($currency), null);
            } catch (\Throwable $cancelError) {
                $this->logger->error('withdrawal.cancel_failed', [
                    'user_id' => $userId,
                    'idempotency_key' => $idempotencyKey,
                    'error' => $cancelError->getMessage()
                ]);
            }

            $this->logger->error('withdrawal.create.failed', [
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => 'خطای سیستمی در ثبت برداشت'];
        }
    }

    /**
     * تأیید برداشت توسط ادمین
     */
    public function adminApprove(int $adminId, int $withdrawalId, array $paymentData): array
    {
        try {
            $this->db->beginTransaction();

            $w = $this->db->query(
                "SELECT * FROM withdrawals WHERE id = :id FOR UPDATE",
                ['id' => $withdrawalId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$w) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'برداشت یافت نشد'];
            }

            if (!$this->stateMachine->canTransition('withdrawal', (string)$w->status, 'completed')) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'تغییر وضعیت غیرمجاز است یا قبلاً انجام شده است'];
            }

            $update = [
                'status'       => 'completed',
                'processed_by' => $adminId,
                'processed_at' => date('Y-m-d H:i:s'),
                'admin_note'   => $paymentData['admin_note'] ?? null,
            ];

            if (strtoupper((string)$w->currency) === 'IRT') {
                $update['bank_tracking_code'] = $paymentData['bank_tracking_code'] ?? null;
            } else {
                $update['transaction_hash'] = $paymentData['transaction_hash'] ?? null;
            }

            if (!$this->wallet->completeWithdrawal(
                (int)$w->user_id,
                (float)$w->amount,
                strtolower((string)$w->currency),
                $w->transaction_id
            )) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در تکمیل برداشت'];
            }


            $this->model->update($withdrawalId, $update);
            $this->db->commit();

            $this->auditTrail->record('withdrawal.approved', (int)$w->user_id, [
                'withdrawal_id' => (int)$withdrawalId,
                'amount'        => (float)$w->amount,
                'currency'      => $w->currency,
                'admin_id'      => $adminId,
            ], $adminId);

            $this->notifier->withdrawalApproved((int)$w->user_id, (float)$w->amount, (string)$w->currency);

            return ['success' => true, 'message' => 'برداشت تکمیل شد'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('withdrawal.approve.failed', ['id' => $withdrawalId, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در تکمیل برداشت'];
        }
    }

    /**
     * رد درخواست برداشت و بازگشت وجه
     */
    public function adminReject(int $adminId, int $withdrawalId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $w = $this->db->query(
                "SELECT * FROM withdrawals WHERE id = :id FOR UPDATE",
                ['id' => $withdrawalId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$w) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'برداشت یافت نشد'];
            }

            if (!$this->stateMachine->canTransition('withdrawal', (string)$w->status, 'rejected')) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'امکان رد این درخواست وجود ندارد (وضعیت نامعتبر)'];
            }

            $userId   = (int)$w->user_id;
            $amount   = (float)$w->amount;
            $currency = strtolower((string)$w->currency);

            $wallet = $this->db->query(
                "SELECT * FROM wallets WHERE user_id = :user_id FOR UPDATE",
                ['user_id' => $userId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$wallet) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'کیف پول کاربر یافت نشد'];
            }

            if (!$this->wallet->cancelWithdrawal($userId, $amount, $currency, $w->transaction_id)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در بازگشت وجه'];
            }

            $this->model->update($withdrawalId, [
                'status'       => 'rejected',
                'admin_note'   => $reason,
                'processed_by' => $adminId,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            $this->db->commit();

            $this->auditTrail->record('withdrawal.rejected', $userId, [
                'withdrawal_id' => (int)$withdrawalId,
                'amount'        => $amount,
                'currency'      => $currency,
                'reason'        => $reason,
                'admin_id'      => $adminId,
            ], $adminId);

            $this->notifier->withdrawalRejected($userId, $amount, $reason);

            return ['success' => true, 'message' => 'برداشت رد شد و وجه برگشت داده شد'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('withdrawal.reject.failed', [
                'id'  => $withdrawalId,
                'err' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در رد برداشت'];
        }
    }

    public function check(int $userId, float $amount, string $currency): array
    {
        $this->logger->info('withdrawal.limit.check.started', [
            'user_id' => $userId,
            'amount'  => $amount,
            'currency'=> $currency,
        ]);

        $user = $this->db->fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            $this->logger->error('withdrawal.limit.user_not_found', ['user_id' => $userId]);
            return ['allowed' => false, 'reason' => 'کاربر یافت نشد', 'limits' => []];
        }

        $profile = $this->resolveProfile($user);
        $limits  = $this->getLimits($currency, $profile);

        if ($limits['daily_count'] === 0) {
            return [
                'allowed' => false,
                'reason'  => 'برای برداشت باید ابتدا احراز هویت (KYC) را تکمیل کنید',
                'limits'  => $limits,
            ];
        }

        $todayCount = $this->getWithdrawalCount($userId, 'day');
        if ($todayCount >= $limits['daily_count']) {
            return [
                'allowed' => false,
                'reason'  => "امروز به سقف برداشت روزانه ({$limits['daily_count']} بار) رسیده‌اید",
                'limits'  => $limits,
            ];
        }

        $weekCount = $this->getWithdrawalCount($userId, 'week');
        if ($weekCount >= $limits['weekly_count']) {
            return [
                'allowed' => false,
                'reason'  => "این هفته به سقف برداشت هفتگی ({$limits['weekly_count']} بار) رسیده‌اید",
                'limits'  => $limits,
            ];
        }

        $monthCount = $this->getWithdrawalCount($userId, 'month');
        if ($monthCount >= $limits['monthly_count']) {
            return [
                'allowed' => false,
                'reason'  => "این ماه به سقف برداشت ماهانه ({$limits['monthly_count']} بار) رسیده‌اید",
                'limits'  => $limits,
            ];
        }

        if ($amount > $limits['max_amount']) {
            return [
                'allowed' => false,
                'reason'  => 'مبلغ بیشتر از سقف مجاز (' . $this->currencyService->formatAmount($limits['max_amount'], $currency) . ') است',
                'limits'  => $limits,
            ];
        }

        if ($amount < $limits['min_amount']) {
            return [
                'allowed' => false,
                'reason'  => 'مبلغ کمتر از حداقل برداشت (' . $this->currencyService->formatAmount($limits['min_amount'], $currency) . ') است',
                'limits'  => $limits,
            ];
        }

        return [
            'allowed'   => true,
            'reason'    => '',
            'limits'    => $limits,
            'remaining' => [
                'daily'   => $limits['daily_count'] - $todayCount,
                'weekly'  => $limits['weekly_count'] - $weekCount,
                'monthly' => $limits['monthly_count'] - $monthCount,
            ],
        ];
    }

    public function getLimitsForUser(int $userId, string $currency): array
    {
        $startTime = microtime(true);
        
        $user    = $this->db->fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        $profile = $this->resolveProfile($user);
        $limits  = $this->getLimits($currency, $profile);

        // ✅ OPTIMIZATION: Consolidated all 3 withdrawal counts into single query using CASE statements
        // Instead of 3 separate queries for day/week/month, use single CASE-based query
        $sql = "
            SELECT 
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as count_day,
                SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1) THEN 1 ELSE 0 END) as count_week,
                SUM(CASE WHEN YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW()) THEN 1 ELSE 0 END) as count_month
            FROM withdrawals
            WHERE user_id = ? AND status NOT IN ('rejected', 'cancelled')
        ";
        
        $counts = $this->db->fetch($sql, [$userId]);
        
        // Track query performance
        $executionTime = microtime(true) - $startTime;
        $this->performance->trackQueryTime('getLimitsForUser (consolidated)', $executionTime);
        
        return array_merge($limits, [
            'used_today'  => (int)($counts->count_day ?? 0),
            'used_week'   => (int)($counts->count_week ?? 0),
            'used_month'  => (int)($counts->count_month ?? 0),
            'profile'     => $profile,
            '_query_optimization' => 'consolidated (3 queries → 1 with CASE)',
        ]);
    }

    private function resolveProfile(object $user): string
    {
        if (isset($user->is_admin) && $user->is_admin) {
            return 'admin';
        }
        if (($user->kyc_status ?? '') !== 'verified') {
            return 'no_kyc';
        }
        return match($user->tier_level ?? 'silver') {
            'gold' => 'gold_kyc',
            'vip'  => 'vip_kyc',
            default => 'silver_kyc',
        };
    }

    private function getLimits(string $currency, string $profile): array
    {
        $profiles = $this->settings->get('withdrawal_profiles', self::PROFILES_DEFAULT);
        $p = $profiles[$profile] ?? $profiles['no_kyc'] ?? self::PROFILES_DEFAULT['no_kyc'];
        $cur = strtolower($currency);

        $baseMin = (float)$this->settings->get("min_withdrawal_{$cur}", $cur === 'irt' ? 50000 : 10);
        $baseMax = (float)$this->settings->get("max_withdrawal_{$cur}", $cur === 'irt' ? 10000000 : 1000);

        return [
            'daily_count'   => $p['daily'],
            'weekly_count'  => $p['weekly'],
            'monthly_count' => $p['monthly'],
            'min_amount'    => $baseMin,
            'max_amount'    => $p['multiplier'] > 0 ? ($baseMax * $p['multiplier']) : 0,
            'currency'      => strtoupper($currency),
            'profile_label' => $this->profileLabel($profile),
        ];
    }

    private function getWithdrawalCount(int $userId, string $period): int
    {
        $condition = match($period) {
            'day'   => 'DATE(created_at) = CURDATE()',
            'week'  => 'YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)',
            'month' => 'YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())',
            default => '1=0',
        };

        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM withdrawals
             WHERE user_id = ? AND status NOT IN ('rejected','cancelled') AND {$condition}",
            [$userId]
        );
    }

    private function profileLabel(string $profile): string
    {
        return match($profile) {
            'no_kyc'     => 'بدون احراز هویت',
            'silver_kyc' => 'Silver (KYC تأیید شده)',
            'gold_kyc'   => 'Gold (KYC تأیید شده)',
            'vip_kyc'    => 'VIP (KYC تأیید شده)',
            'admin'      => 'ادمین',
            default      => $profile,
        };
    }

    // ─── private helpers ──────────────────────────────────────────

    private function checkDailyLimit(int $userId, int $limit): bool
    {
        return $this->limitModel->checkDailyLimit($userId, $limit);
    }

    private function increaseDailyLimit(int $userId): void
    {
        $this->limitModel->incrementDailyCount($userId);
    }

    public function recordTransactionStatusChange(
        string $transactionId,
        string $newStatus,
        string $reason,
        int    $changedBy,
        array  $metadata = []
    ): void {
        $metadata['ip_address'] = $this->clientIp();
        $this->transactionModel->recordStatusChange(
            $transactionId, $newStatus, $reason, $changedBy, $metadata
        );
    }

    public function getAll(?string $status = null, ?string $currency = null, int $limit = 50, int $offset = 0): array
    {
        return $this->model->getAll($status, $currency, $limit, $offset);
    }

    public function countAll(?string $status = null, ?string $currency = null): int
    {
        return $this->model->countAll($status, $currency);
    }

    public function getPendingWithdrawals(int $limit = 50, int $offset = 0): array
    {
        return $this->model->getPendingWithdrawals($limit, $offset);
    }

    public function countPendingWithdrawals(): int
    {
        return $this->model->countPendingWithdrawals();
    }

    public function getSummaryStats(): array
    {
        return $this->model->getSummaryStats();
    }

    public function findById(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function updateStatus(
        int $id,
        string $status,
        ?string $reason = null,
        ?int $adminId = null,
        ?string $transactionId = null
    ): bool {
        return $this->model->updateStatus($id, $status, $reason, $adminId, $transactionId);
    }

    /**
     * بررسی وجود درخواست برداشت در انتظار برای کاربر
     */
    public function hasPendingWithdrawal(int $userId): bool
    {
        return $this->model->hasPendingWithdrawal($userId);
    }

    /**
     * دریافت تمام درخواست‌های برداشت مربوط به یک کاربر
     */
    public function getUserWithdrawals(
        int $userId,
        ?string $status = null,
        ?string $currency = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        return $this->model->getUserWithdrawals($userId, $status, $currency, $limit, $offset);
    }

    /**
     * تأیید و پرداخت نهایی درخواست برداشت توسط مدیر
     */
    public function approveWithdrawal(int $withdrawalId, string $paymentReference, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            // H21 Fix: اضافه کردن قفل بدبینانه روی رکورد برداشت جهت جلوگیری قطعی از Double Deduction در ریکوئست‌های همزمان
            $withdrawal = $this->db->query("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);

            if (!$withdrawal) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست یافت نشد'];
            }

            if ($withdrawal->status !== 'pending') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'این درخواست قبلاً پردازش شده است یا در وضعیت غیرقابل تایید قرار دارد'];
            }

            // 1. تکمیل برداشت در کیف پول
            $completed = $this->wallet->completeWithdrawal(
                (int)$withdrawal->user_id,
                (float)$withdrawal->amount,
                (string)$withdrawal->currency,
                (string)$withdrawal->transaction_id
            );

            if (!$completed) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در نهایی‌سازی تراکنش در کیف پول'];
            }

            // 2. تغییر وضعیت به completed
            $updated = $this->model->updateStatus($withdrawalId, 'completed', $paymentReference, $adminId);
            if (!$updated) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در به‌روزرسانی وضعیت درخواست'];
            }

            // 3. ثبت ایونت تراکنش
            if (method_exists($this, 'recordTransactionStatusChange')) {
                $this->recordTransactionStatusChange(
                    (string)$withdrawal->transaction_id,
                    'completed',
                    "تایید و پرداخت توسط ادمین | مرجع: {$paymentReference}",
                    $adminId,
                    [
                        'payment_reference' => $paymentReference,
                        'withdrawal_id' => $withdrawalId
                    ]
                );
            }

            $this->db->commit();

            // 4. عملیات تطبیق (خارج از Transaction اتمیک به عنوان Best Practice)
            try {
                $recon = $this->reconciliation->reconcilePayment([
                    'transaction_id' => (string)$withdrawal->transaction_id,
                    'reference_id' => 'withdrawal_settlement_' . $withdrawalId,
                    'user_id' => (int)$withdrawal->user_id,
                    'amount' => (float)$withdrawal->amount,
                    'currency' => $withdrawal->currency,
                    'status' => 'success',
                    'gateway' => 'withdrawal_bank',
                    'description' => "تطبیق اتوماتیک - مرجع: {$paymentReference}",
                    'timestamp' => time(),
                ]);

                if (empty($recon['success'])) {
                    $this->logger->warning('withdrawal.approve.reconcile_failed', [
                        'withdrawal_id' => $withdrawalId,
                        'error' => $recon['message'] ?? 'Unknown'
                    ]);
                }
            } catch (\Throwable $reconEx) {
                $this->logger->error('withdrawal.approve.reconcile_exception', [
                    'withdrawal_id' => $withdrawalId,
                    'error' => $reconEx->getMessage()
                ]);
            }

            $this->logger->info('withdrawal.approve.success', [
                'withdrawal_id' => $withdrawalId,
                'admin_id' => $adminId
            ]);

            return ['success' => true, 'message' => 'برداشت با موفقیت تایید و پرداخت گردید'];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->critical('withdrawal.approve.critical_failure', [
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return ['success' => false, 'message' => 'بروز خطا در سیستم هنگام تایید برداشت'];
        }
    }

    /**
     * جستجوی سریع درخواست‌های برداشت برای سیستم سرچ مرکزی
     */
    public function quickSearchWithdrawals(string $term, int $limit = 5): array
    {
        $query = $this->model->query()
            ->select('withdrawals.id', 'withdrawals.amount', 'withdrawals.currency', 'withdrawals.status', 'withdrawals.created_at', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'withdrawals.user_id');

        // ۱. جستجو روی فیلدهای خود برداشت
        $this->model->applySearch($query, $term);

        // ۲. فیلترهای الحاقی برای ایمیل و آیدی دقیق
        if (!empty($term)) {
            $term = trim($term);
            $escaped = addcslashes($term, '%_');
            $like = "%{$escaped}%";
            
            $query->where(function($sub) use ($like, $term) {
                $sub->orWhere('u.email', 'LIKE', $like);
                if (\is_numeric($term)) {
                     $sub->orWhere('withdrawals.id', '=', (int)$term);
                }
            });
        }

        return $query->orderBy('withdrawals.created_at', 'DESC')
                     ->limit($limit)
                     ->get() ?? [];
    }
}
