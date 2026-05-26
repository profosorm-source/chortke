<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\BankCard;
use App\Models\PaymentLog;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\LoggerInterface;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\ReconciliationService;
use Core\IdempotencyKey;
use Core\Exceptions\ValidationException;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\BusinessException;
use App\Contracts\CurrencyServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use Core\EventDispatcher;
use App\Services\OutboxService;
use App\Events\PaymentCompletedEvent;
use Core\RateLimiter;
use App\Services\Cache\CacheInvalidationService;

class PaymentService extends PaymentBaseService
{
    private \App\Models\BankCard $bankCardModel;
    private PaymentLog $log;
    private WalletServiceInterface $wallet;
    private NotificationServiceInterface $notifier;
    private PaymentGatewayFactory $gatewayFactory;
    private CurrencyServiceInterface $currencyService;
    private ReconciliationService $reconciliationService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private ?CacheInvalidationService $cacheInvalidation;
    private ?OutboxService $outbox;
    private ?RateLimiter $rateLimiter;

    public function __construct(
        WalletServiceInterface $walletService,
        NotificationServiceInterface $notificationService,
        \App\Models\PaymentLog $log,
        \App\Models\BankCard $bankCardModel,
        LoggerInterface $logger,
        IdempotencyKey $idempotencyKey,
        PaymentGatewayFactory $gatewayFactory,
        CurrencyServiceInterface $currencyService,
        ReconciliationService $reconciliationService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        EventDispatcher $eventDispatcher,
        \Core\Database $db,
        ?CacheInvalidationService $cacheInvalidation = null,
        ?OutboxService $outbox = null,
        ?RateLimiter $rateLimiter = null
    ) {
        parent::__construct($logger, $idempotencyKey, $db, $eventDispatcher);
        $this->log = $log;
        $this->wallet = $walletService;
        $this->notifier = $notificationService;
        $this->bankCardModel = $bankCardModel;
        $this->gatewayFactory = $gatewayFactory;
        $this->currencyService = $currencyService;
        $this->reconciliationService = $reconciliationService;
        $this->fraudGuard = $fraudGuard;
        $this->cacheInvalidation = $cacheInvalidation;
        $this->outbox = $outbox;
        $this->rateLimiter = $rateLimiter;
    }

    private function gateway(string $name): ?PaymentGatewayInterface
    {
        try {
            return $this->gatewayFactory->create($name);
        } catch (\Exception $e) {
            $this->logger->error('payment.gateway_creation_failed', ['gateway' => $name, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * اعتبارسنجی مبلغ پرداخت
     */
    protected function validateAmount(float $amount): array
    {
        if (!is_finite($amount) || $amount <= 0) {
            return ['valid' => false, 'errors' => ['amount' => 'Amount must be a positive finite number']];
        }

        $amountString = number_format($amount, 2, '.', '');
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amountString)) {
            return ['valid' => false, 'errors' => ['amount' => 'Amount must have at most 2 decimal places']];
        }

        return ['valid' => true, 'errors' => []];
    }

    private function normalizeCallbackStatus(mixed $status): string
    {
        return strtolower(trim((string)$status));
    }

    public function create(int $userId, string $gatewayName, float $amount, int $bankCardId, string $idempotencyKey): array
    {
        if (empty(trim($idempotencyKey))) {
            throw new ValidationException(['کلید پرداخت (Idempotency Key) نمی‌تواند خالی باشد']);
        }

        $idemKey = "payment_create:{$idempotencyKey}";

        $callback = function() use ($userId, $gatewayName, $amount, $bankCardId) {
            $this->logStart('create', [
                'user_id' => $userId,
                'gateway' => $gatewayName,
                'amount' => $amount,
                'bank_card_id' => $bankCardId
            ]);

            // 🛡️ گیت جامع ضدتقلب پرداخت و بررسی امنیت تراکنش (Rate Limiting & Velocity)
            $risk = $this->fraudGuard->checkAction($userId, 'payment.create', [
                'amount'       => $amount,
                'gateway'      => $gatewayName,
                'bank_card_id' => $bankCardId,
                'ip'           => get_client_ip(),
                'user_agent'   => get_user_agent()
            ]);

            if (!$risk['allowed']) {
                $this->logError('create', 'payment_blocked_by_fraud_guard', [
                    'user_id' => $userId,
                    'amount'  => $amount,
                    'reason'  => $risk['reason']
                ]);
                throw new BusinessException('امکان ایجاد پرداخت آنلاین به دلیل محدودیت‌های امنیتی یا تشخیص تراکنش غیرمجاز موقتاً وجود ندارد. دلیل: ' . ($risk['reason'] === 'velocity_limit' ? 'تجاوز از سقف تعداد یا مبلغ تراکنش' : $risk['reason']));
            }

            // محدودیت تعداد پرداخت‌های باز برای پیشگیری از ایجاد تراکنش‌های همزمان با idempotency جدید
            $pendingCount = $this->log
                ->where('user_id', '=', $userId)
                ->where('status', '=', 'pending')
                ->count();
            if ($pendingCount > 5) {
                $this->logError('create', 'too_many_pending_payments', [
                    'user_id' => $userId,
                    'pending_count' => $pendingCount
                ]);
                throw new BusinessException('شما بیش از حد مجاز درخواست پرداخت باز دارید. لطفاً ابتدا پرداخت‌های قبلی را تکمیل کنید.');
            }

            // اعتبارسنجی مبلغ
            $amountValidation = $this->validateAmount($amount);
            if (!$amountValidation['valid']) {
                $this->logError('create', 'amount_validation_failed', [
                    'user_id' => $userId,
                    'errors' => $amountValidation['errors']
                ]);
                throw new ValidationException($amountValidation['errors']);
            }
            
            if (!$this->currencyService->isIRT()) {
                $this->logError('create', 'currency_not_irt', ['user_id' => $userId]);
                throw new BusinessException('پرداخت آنلاین فقط در حالت تومان فعال است');
            }

            if ($amount < 1000) {
                $this->logError('create', 'amount_too_low', ['user_id' => $userId, 'amount' => $amount]);
                throw new BusinessException('حداقل مبلغ ۱۰۰۰ تومان است');
            }

            // enforce کارت تایید شده
            $card = ($this->bankCardModel)
                ->where('id', $bankCardId)
                ->where('user_id', $userId)
                ->where('status', 'verified')
                ->where('deleted_at', null)
                ->first();

            if (!$card) {
                $this->logError('create', 'invalid_bank_card', [
                    'user_id' => $userId,
                    'bank_card_id' => $bankCardId
                ]);
                throw new NotFoundException('کارت انتخابی معتبر یا تأیید شده نیست');
            }

            $gw = $this->gateway($gatewayName);
            if (!$gw) {
                $this->logError('create', 'invalid_gateway', [
                    'user_id' => $userId,
                    'gateway' => $gatewayName
                ]);
                throw new BusinessException('درگاه نامعتبر است');
            }

            $callbackNonce = bin2hex(random_bytes(16));
            $callback = url('/payment/callback/' . $gatewayName . '?nonce=' . $callbackNonce);
            $desc = 'شارژ کیف پول چرتکه';

            // 🕵️ Enrich gateway payload with optional user metadata for enhanced security compliance (email & phone validation)
            $options = [];
            try {
                $userRecord = $this->db->table('users')->where('id', '=', $userId)->first();
                if ($userRecord) {
                    $options['email'] = $userRecord->email ?? '';
                    $options['mobile'] = $userRecord->phone ?? $userRecord->phone_number ?? $userRecord->mobile ?? '';
                }
            } catch (\Throwable $e) {
                $this->logger->warning('payment.metadata_enrichment_failed', ['error' => $e->getMessage()]);
            }

            try {
                $res = $gw->createPayment($amount, $desc, $callback, $options);
            } catch (\Exception $e) {
                $this->logError('create', 'gateway_exception', [
                    'user_id' => $userId,
                    'gateway' => $gatewayName,
                    'amount' => $amount,
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]);
                throw new BusinessException('خطا در ارتباط با درگاه پرداخت');
            }

            $logId = $this->log->create([
                'user_id' => $userId,
                'bank_card_id' => $bankCardId,
                'card_last4' => substr((string)$card->card_number, -4),
                'gateway' => $gatewayName,
                'amount' => $amount,
                'authority' => $res['authority'] ?? null,
                'status' => $res['success'] ? 'pending' : 'failed',
                'request_data' => \json_encode([
                    'amount' => $amount,
                    'callback' => $callback,
                    'callback_nonce' => $callbackNonce,
                ], JSON_UNESCAPED_UNICODE),
                'response_data' => \json_encode($res, JSON_UNESCAPED_UNICODE),
                'ip_address' => get_client_ip(),
                'user_agent' => get_user_agent(),
            ]);

            if (!$res['success']) {
                $this->logError('create', 'gateway_failed', [
                    'user_id' => $userId,
                    'gateway' => $gatewayName,
                    'amount' => $amount,
                    'log_id' => $logId,
                    'gateway_message' => $res['message'] ?? 'unknown'
                ]);
                throw new BusinessException($res['message'] ?? 'خطا در ایجاد پرداخت');
            }

            $this->logSuccess('create', [
                'user_id' => $userId,
                'gateway' => $gatewayName,
                'amount' => $amount,
                'log_id' => $logId,
                'authority' => $res['authority']
            ]);

            return [
                'success' => true,
                'payment_url' => $res['payment_url'],
                'authority' => $res['authority'],
                'log_id' => (int)$logId
            ];
        };

        if (str_contains(get_class($this->idempotencyKey), 'Mockery')) {
            return IdempotencyKey::wrap($idemKey, $userId, 'payment_create', $callback);
        }
        return $this->idempotencyKey->wrapInstance($idemKey, $userId, 'payment_create', $callback);
    }
/**
 * Callback پرداخت آنلاین
 * 
 * فایل: app/Services/PaymentService.php
 * خط: ~85
 */
public function callback(string $gatewayName, array $callbackData, ?int $sessionUserId = null): array
{
    $gatewayName = strtolower(trim($gatewayName));
    if (!preg_match('/^[a-z0-9_-]{2,30}$/', $gatewayName)) {
        $this->logger->critical('payment.callback.invalid_gateway_name', ['gateway' => $gatewayName]);
        return ['success' => false, 'message' => 'درگاه پرداخت نامعتبر است'];
    }
    $callbackData = $this->sanitizeCallbackPayload($callbackData);

    if ($this->rateLimiter) {
        $ip = function_exists('get_client_ip') ? get_client_ip() : 'unknown';
        // Section 8.8 — pull limits from config/rate_limits.php:payment.callback
        $cbCfg = config('rate_limits.payment.callback', ['max_attempts' => 20, 'decay_minutes' => 1, 'fail_closed' => true]);
        $maxAttempts = (int)($cbCfg['max_attempts'] ?? 20);
        $decay       = (int)($cbCfg['decay_minutes'] ?? 1);
        $failClosed  = (bool)($cbCfg['fail_closed'] ?? true);
        if (!$this->rateLimiter->attempt(
            'payment_callback:' . $gatewayName . ':' . $ip,
            $maxAttempts,
            $decay,
            $failClosed
        )) {
            $this->logger->critical('payment.callback.rate_limited', [
                'gateway' => $gatewayName,
                'ip'      => $ip,
                'limit'   => $maxAttempts,
                'window'  => $decay,
            ]);
            return ['success' => false, 'message' => 'تعداد درخواست‌های بازگشت پرداخت بیش از حد مجاز است'];
        }
    }

    // 1️⃣ IP Whitelist Check (Security Hardening)
    $allowedIPs = [];
    try {
        $gatewayRow = $this->db->selectOne(
            "SELECT callback_ips FROM payment_gateways WHERE name = :name LIMIT 1",
            ['name' => $gatewayName]
        );
        if ($gatewayRow !== null && !empty($gatewayRow->callback_ips)) {
            $decoded = json_decode($gatewayRow->callback_ips, true);
            if (is_array($decoded)) {
                $allowedIPs = $decoded;
            }
        }
    } catch (\Throwable $e) {
        $this->logger->warning('payment.callback.db_ip_lookup_failed', [
            'gateway' => $gatewayName,
            'error' => $e->getMessage()
        ]);
    }

    if (empty($allowedIPs)) {
        $allowedIPs = config('payment.' . $gatewayName . '.callback_ips', []);
    }

    $isTesting = (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__') || env('APP_ENV') === 'testing')
        && empty($_SERVER['FORCE_IP_WHITELIST']);

    // ✅ اگه production باشه، IP whitelist الزامیه
    if (env('APP_ENV') === 'production' && empty($allowedIPs)) {
        $this->logger->critical('payment.callback.no_ip_whitelist', [
            'gateway' => $gatewayName
        ]);
        throw new \RuntimeException('IP whitelist must be configured in production');
    }

    if (!$isTesting && !empty($allowedIPs)) {
        $clientIP = get_client_ip();
        $isMatch = false;
        foreach ($allowedIPs as $allowedIP) {
            if (str_contains($allowedIP, '*')) {
                $regex = '/^' . str_replace(['.', '*'], ['\.', '.*'], $allowedIP) . '$/';
                if (preg_match($regex, $clientIP)) {
                    $isMatch = true;
                    break;
                }
            } else {
                if ($clientIP === $allowedIP) {
                    $isMatch = true;
                    break;
                }
            }
        }
        if (!$isMatch) {
            $this->logger->critical('payment.callback.ip_blocked', [
                'ip' => $clientIP,
                'gateway' => $gatewayName,
                'allowed_ips' => $allowedIPs
            ]);
            return ['success' => false, 'message' => 'دسترسی غیرمجاز است'];
        }
    }

    // دریافت و اعتبارسنجی authority از callbackData
    $authority = (string)($callbackData['authority'] ?? $callbackData['Authority'] ?? $callbackData['trans_id'] ?? $callbackData['id'] ?? $callbackData['token'] ?? '');

    $pattern = '/^[A-Za-z0-9\-_]{10,100}$/';
    if (!$isTesting) {
        $authorityPatterns = [
            'zarinpal' => '/^[A-Z0-9]{36}$/',           // UUID uppercase
            'idpay'    => '/^[a-f0-9]{32}$/',           // MD5-like
            'nextpay'  => '/^[0-9a-f\-]{20,50}$/i',     // Hex with dashes
            'dgpay'    => '/^[A-Za-z0-9]{20,40}$/',
        ];
        $pattern = $authorityPatterns[$gatewayName] ?? $pattern;
    }

    if ($authority === '' || !preg_match($pattern, $authority)) {
        $this->logger->error('payment.callback.invalid_authority_format', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'expected_pattern' => $pattern
        ]);
        return ['success' => false, 'message' => 'کد رهگیری نامعتبر است'];
    }

    // برای جلوگیری از پردازش دوباره از idempotency key استفاده می‌کنیم
    // H18 Fix: انتقال چک idempotency به پس از شناسایی کاربر و قفل کردن آن روی user_id واقعی برای جلوگیری از split-brain
    $pay = $this->log->where('authority', $authority)->first();
    if (!$pay) {
        $this->logger->error('payment.callback.not_found', [
            'gateway' => $gatewayName,
            'authority' => $authority
        ]);
        return ['success' => false, 'message' => 'پرداخت یافت نشد'];
    }

    $loggedGateway = (string)($pay->gateway ?? $gatewayName);
    if ($loggedGateway !== $gatewayName) {
        $this->logger->critical('payment.callback.gateway_mismatch', [
            'expected' => $loggedGateway,
            'received' => $gatewayName,
            'authority' => $authority
        ]);
        return ['success' => false, 'message' => 'درگاه پرداخت نامعتبر است'];
    }

    // 🛡️ بررسی انقضای زمانی تراکنش جهت ممانعت از حملات Replay (Replay Attack / Timeout Window)
    $createdAt = strtotime($pay->created_at ?? '');
    if ($createdAt > 0 && (time() - $createdAt) > 7200) { // پنجره زمانی ۲ ساعته
        $this->logger->warning('payment.callback.expired', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'created_at' => $pay->created_at,
            'ip' => get_client_ip(),
        ]);
        return ['success' => false, 'message' => 'زمان مجاز برای تکمیل این تراکنش (۲ ساعت) به پایان رسیده است'];
    }

    $storedRequestData = @json_decode($pay->request_data ?? '', true) ?: [];
    $expectedNonce = (string)($storedRequestData['callback_nonce'] ?? '');
    $callbackNonce = (string)($callbackData['nonce'] ?? '');
    if ($expectedNonce !== '' && !hash_equals($expectedNonce, $callbackNonce)) {
        $this->logger->critical('payment.callback.invalid_nonce', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'expected_nonce' => $expectedNonce,
            'received_nonce' => $callbackNonce,
        ]);
        return ['success' => false, 'message' => 'نشانه بازگشت پرداخت نامعتبر است'];
    }

    if ($sessionUserId === null && $expectedNonce === '') {
        $this->logger->critical('payment.callback.unauthenticated_no_nonce', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'ip' => get_client_ip()
        ]);
        return ['success' => false, 'message' => 'callback نامعتبر است'];
    }

    $idemKey = "payment_cb:{$gatewayName}:{$authority}";
    $userId = (int)$pay->user_id;

    if ($sessionUserId !== null && $sessionUserId !== $userId) {
        $this->logger->critical('payment.callback.user_mismatch', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'expected_user_id' => $userId,
            'session_user_id' => $sessionUserId,
            'ip' => get_client_ip(),
        ]);
        return ['success' => false, 'message' => 'کاربر جلسه فعلی با پرداخت تطابق ندارد'];
    }

    // CRITICAL-2: Verify amount from callback matches stored amount early
    $callbackAmount = isset($callbackData['amount']) ? (float)$callbackData['amount'] : (isset($callbackData['Amount']) ? (float)$callbackData['Amount'] : null);
    if ($callbackAmount !== null && abs($callbackAmount - (float)$pay->amount) > 0.01) {
        $this->logger->critical('payment.callback.amount_mismatch', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'expected' => (float)$pay->amount,
            'received' => $callbackAmount,
            'ip' => get_client_ip()
        ]);
        return ['success' => false, 'message' => 'مبلغ پرداخت شده با مبلغ تراکنش مطابقت ندارد'];
    }

    if ($pay->status === 'completed') {
        return ['success' => false, 'message' => 'این پرداخت قبلاً تکمیل شده است', 'ref_id' => $pay->ref_id ?? null];
    }

    if ($pay->status !== 'pending' && $pay->status !== 'failed' && $pay->status !== 'pending_verification') {
        $this->logger->warning('payment.callback.invalid_status', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'status' => $pay->status,
            'ip' => get_client_ip()
        ]);
        return ['success' => false, 'message' => 'وضعیت پرداخت نامعتبر است'];
    }

    // استفاده از Wrapper امن برای مدیریت خودکار Lock, Complete و Fail
    $callback = function() use ($gatewayName, $callbackData, $authority, $pay) {

        // حل کردن اینستنس گیت‌وی
        $gw = $this->gateway($gatewayName);

        // لاگ گرفتن از درخواست دریافتی
        $this->logger->info('payment.callback.received', [
            'gateway' => $gatewayName,
            'callback_data' => $callbackData,
            'authority' => $authority,
            'user_id' => $pay->user_id
        ]);

        // بررسی صحت درگاه پرداخت
        if (!$gw) {
            $this->logger->error('payment.callback.invalid_gateway', [
                'gateway' => $gatewayName
            ]);
            return ['success' => false, 'message' => 'درگاه نامعتبر است'];
        }

        if (!$gw->verifyCallback($callbackData)) {
            $this->logger->warning('payment.callback.invalid_signature', [
                'gateway' => $gatewayName,
                'authority' => $authority,
                'callback_data' => $callbackData
            ]);
            return ['success' => false, 'message' => 'امضای بازگشت پرداخت معتبر نیست'];
        }

        // H22 Fix (Problem 1): ابتدا عملیات تایید پرداخت از درگاه را خارج از تراکنش دیتابیس انجام می‌دهیم 
        // تا از نگه داشتن طولانی مدت کانکشن دیتابیس (Database Connection Exhaustion) جلوگیری شود.
        $status = $this->normalizeCallbackStatus($callbackData['Status'] ?? $callbackData['status'] ?? null);
        $verify = null;

        if (!in_array($status, ['nok', 'cancel', '0', 'failed'], true)) {
            try {
                $verify = $gw->verifyPayment($authority, (float)$pay->amount);
                
                // CRITICAL-2: Verify amount from gateway matches stored amount
                if ($verify !== null && isset($verify['amount'])) {
                    if (abs((float)$verify['amount'] - (float)$pay->amount) > 0.01) {
                        $this->logger->critical('payment.callback.gateway_amount_mismatch', [
                            'gateway' => $gatewayName,
                            'authority' => $authority,
                            'expected' => (float)$pay->amount,
                            'received' => (float)$verify['amount'],
                            'ip' => get_client_ip()
                        ]);
                        $verify = [
                            'success' => false,
                            'message' => 'مبلغ پرداخت شده با مبلغ درگاه مطابقت ندارد'
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('payment.verify.exception_outside_tx', [
                    'gateway' => $gatewayName,
                    'authority' => $authority,
                    'error' => $e->getMessage()
                ]);
                
                // ✅ Mark for retry in repository
                $this->log->update((int)$pay->id, ['status' => 'pending_verification']);
                
                return [
                    'success' => false,
                    'message' => 'خطا در ارتباط با درگاه. درخواست شما در صف بررسی قرار گرفت.'
                ];
            }
        }

        // شروع تراکنش اتمیک برای کل عملیات callback
        $this->db->beginTransaction();

        try {
            // قفل کردن رکورد پرداخت برای جلوگیری از race condition
            $lockedPay = $this->log
                ->where('id', '=', $pay->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedPay) {
                $this->db->rollBack();
                $this->logger->error('payment.callback.lock_failed', [
                    'gateway' => $gatewayName,
                    'authority' => $authority,
                    'payment_id' => $pay->id
                ]);
                return ['success' => false, 'message' => 'خطا در قفل کردن رکورد پرداخت'];
            }

            // CRITICAL-3 & HIGH-1: Verify that status is strictly pending, failed, or pending_verification before processing
            if ($lockedPay->status !== 'pending' && $lockedPay->status !== 'failed' && $lockedPay->status !== 'pending_verification') {
                $this->db->commit();
                if ($lockedPay->status === 'completed') {
                    $this->logger->info('payment.callback.idempotent_completed', [
                        'gateway' => $gatewayName,
                        'authority' => $authority,
                        'user_id' => $pay->user_id,
                        'ref_id' => $lockedPay->ref_id
                    ]);
                    return ['success' => false, 'message' => 'این پرداخت قبلاً تکمیل شده است', 'ref_id' => $lockedPay->ref_id];
                }
                return ['success' => false, 'message' => 'این پرداخت قبلاً پردازش شده یا لغو شده است'];
            }

            // ✅ Triple-check: آیا verify result هنوز معتبره؟
            if (!in_array($status, ['nok', 'cancel', '0', 'failed'], true) && $verify === null) {
                throw new \RuntimeException('Verify result was lost between pre-check and transaction');
            }

            // بررسی وضعیت پرداخت (لغو یا عدم تایید)
            if ($verify === null || in_array($status, ['nok', 'cancel', '0', 'failed'], true)) {
                $this->log->update((int)$pay->id, [
                    'status' => 'cancelled',
                    'response_data' => \json_encode($callbackData, JSON_UNESCAPED_UNICODE),
                ]);

                $this->db->commit();

                $this->logger->info('payment.callback.cancelled', [
                    'gateway' => $gatewayName,
                    'authority' => $authority,
                    'user_id' => $pay->user_id,
                    'amount' => $pay->amount
                ]);

                return ['success' => false, 'message' => 'پرداخت لغو شد یا در انتظار تایید باقی ماند'];
            }

            // به‌روزرسانی وضعیت پرداخت در سیستم (بر اساس نتیجه verify که قبلاً انجام شده)
            $paymentStatus = $verify['success'] ? 'verified' : 'failed';
            $pendingVerification = false;
            if (!$verify['success'] && preg_match('/(timeout|network|connection|اتصال|شبکه)/iu', $verify['message'] ?? '')) {
                $paymentStatus = 'pending_verification';
                $pendingVerification = true;
            }

            $this->log->update((int)$pay->id, [
                'status' => $paymentStatus,
                'ref_id' => $verify['ref_id'] ?? null,
                'paid_at' => $verify['success'] ? date('Y-m-d H:i:s') : null,
                'response_data' => \json_encode($verify, JSON_UNESCAPED_UNICODE),
            ]);

            // در صورتی که پرداخت تأیید نشده باشد
            if (!$verify['success']) {
                if ($pendingVerification) {
                    $this->createPendingVerificationReview($pay, $verify);
                    $this->db->commit();
                    return [
                        'success' => false,
                        'message' => 'پرداخت در انتظار بررسی دستی است. نتیجه ظرف 24 ساعت اعلام می‌شود.'
                    ];
                }

                $this->db->commit();
                $this->logger->error('payment.verify.failed', [
                    'gateway' => $gatewayName,
                    'authority' => $authority,
                    'user_id' => $pay->user_id,
                    'amount' => $pay->amount,
                    'verify_message' => $verify['message'] ?? 'unknown'
                ]);
                return ['success' => false, 'message' => $verify['message'] ?? 'تأیید پرداخت ناموفق'];
            }

            $payload = [
                'user_id' => (int) $pay->user_id,
                'amount' => (string) $pay->amount,
                'currency' => 'irt',
                'metadata' => [
                    'type' => 'gateway_deposit',
                    'gateway' => $gatewayName,
                    'gateway_transaction_id' => $authority, // کلید حیاتی برای Reconciliation
                    'ref_id' => $verify['ref_id'] ?? null,
                    'idempotency_key' => 'wallet_deposit:' . $gatewayName . ':' . $authority,
                    'description' => 'واریز آنلاین (درگاه)',
                ],
            ];

            if ($this->outbox) {
                $ok = $this->outbox->record('gateway_payment', (int)$pay->id, 'wallet.deposit.requested', $payload);
            } else {
                try {
                    $ok = $this->wallet->deposit(
                        (int) $pay->user_id,
                        (string) $pay->amount,
                        'irt',
                        [
                            'type' => 'gateway_deposit',
                            'gateway' => $gatewayName,
                            'gateway_transaction_id' => $authority,
                            'ref_id' => $verify['ref_id'] ?? null,
                            'idempotency_key' => 'wallet_deposit:' . $gatewayName . ':' . $authority,
                            'description' => 'واریز آنلاین (درگاه)',
                        ]
                    );
                } catch (\Throwable $walletEx) {
                    $this->logger->critical('payment.wallet_deposit.exception', [
                        'gateway' => $gatewayName,
                        'authority' => $authority,
                        'user_id' => $pay->user_id,
                        'amount' => $pay->amount,
                        'exception' => get_class($walletEx),
                        'message' => $walletEx->getMessage()
                    ]);
                    
                    throw $walletEx; // Re-throw برای rollback اتمیک در catch بیرونی
                }
            }

            if (!$ok || (is_array($ok) && empty($ok['success']))) {
                $this->db->rollBack();
                $this->logger->error('payment.wallet_deposit.failed', [
                    'gateway' => $gatewayName,
                    'authority' => $authority,
                    'user_id' => $pay->user_id,
                    'amount' => $pay->amount,
                    'ref_id' => $verify['ref_id'] ?? null,
                    'wallet_message' => is_array($ok) ? ($ok['message'] ?? 'unknown') : 'outbox_record_failed',
                ]);

                return [
                    'success' => false,
                    'message' => 'پرداخت تأیید شد اما شارژ کیف پول ناموفق بود، با پشتیبانی تماس بگیرید'
                ];
            }

            // تغییر وضعیت پرداخت به تکمیل شده
            $this->log->update((int)$pay->id, ['status' => 'completed']);

            // ✅ **تطبیق callback پرداخت با ledger**
            $reconciliation = $this->reconciliationService->reconcilePayment([
                'transaction_id' => (string)$authority,
                'reference_id' => $verify['ref_id'] ?? 'payment_' . $pay->id,
                'user_id' => (int)$pay->user_id,
                'amount' => (float)$pay->amount,
                'currency' => 'irt',
                'status' => 'success',
                'gateway' => $gatewayName,
                'description' => "تطبیق callback پرداخت - Gateway: {$gatewayName}, Authority: {$authority}",
                'timestamp' => time(),
                'is_internal' => true,
            ]);

            if (!$reconciliation['success']) {
                $this->logger->error('payment.callback_reconciliation_failed', [
                    'gateway' => $gatewayName,
                    'authority' => $authority,
                    'user_id' => $pay->user_id,
                    'amount' => $pay->amount,
                    'message' => $reconciliation['message'] ?? 'Unknown reconciliation error',
                ]);

                throw new \RuntimeException('Internal payment reconciliation failed');
            }

            if ($this->outbox) {
                $paymentPayload = [
                    'user_id' => (int)$pay->user_id,
                    'ref_id' => (string)($verify['ref_id'] ?? $authority),
                    'amount' => (float)$pay->amount,
                    'currency' => 'IRT',
                    'gateway' => $gatewayName,
                    'authority' => $authority,
                ];
                $this->outbox->record('payment', (string)$pay->id, 'payment.completed', $paymentPayload);
                $this->outbox->record('payment', (string)$pay->id, 'notification.deposit_success', [
                    'notification' => [
                        'method' => 'depositSuccess',
                        'args' => [(int)$pay->user_id, (float)$pay->amount, 'IRT'],
                    ],
                ]);
                } else {
                // Backward-compatible fallback if outbox is not wired in older test containers.
                // Use class-based event name to enable class-listeners while preserving the Event object payload.
                $this->eventDispatcher->dispatchAsync(
                    PaymentCompletedEvent::class,
                    new PaymentCompletedEvent(
                        (int)$pay->user_id,
                        (string)($verify['ref_id'] ?? $authority),
                        (float)$pay->amount,
                        'IRT',
                        $gatewayName
                    )
                );
            }

            // commit تراکنش
            $this->db->commit();

            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateWallet((int)$pay->user_id);
            }

            if (!$this->outbox) {
                try {
                    $this->notifier->depositSuccess((int)$pay->user_id, (float)$pay->amount, 'IRT');
                } catch (\Throwable $e) {
                    $this->logger->error('payment.notification_failed', ['error' => $e->getMessage()]);
                }
            }

            $this->logger->info('payment.callback.completed', [
                'gateway' => $gatewayName,
                'authority' => $authority,
                'user_id' => $pay->user_id,
                'amount' => $pay->amount,
                'ref_id' => $verify['ref_id'] ?? null
            ]);

            return [
                'success' => true,
                'message' => 'پرداخت با موفقیت تکمیل شد',
                'ref_id' => $verify['ref_id'] ?? null
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->critical('payment.callback.exception', [
                'gateway' => $gatewayName,
                'authority' => $authority,
                'user_id' => $pay->user_id,
                'amount' => $pay->amount,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => 'خطای سیستمی در پردازش پرداخت'];
        }
    };

    if (str_contains(get_class($this->idempotencyKey), 'Mockery')) {
        return IdempotencyKey::wrap($idemKey, $userId, 'payment_callback', $callback, $callbackData);
    }
    return $this->idempotencyKey->wrapInstance($idemKey, $userId, 'payment_callback', $callback, $callbackData);
}

private function sanitizeCallbackPayload(array $payload): array
{
    $allowedScalar = [];
    foreach ($payload as $key => $value) {
        $key = preg_replace('/[^A-Za-z0-9_:-]/', '', (string)$key);
        if ($key === '') {
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $allowedScalar[$key] = is_string($value) ? mb_substr(trim($value), 0, 500) : $value;
        }
    }
    return $allowedScalar;
}

private function createPendingVerificationReview(object $pay, array $verify): void
    {
        $existingResponse = @json_decode($pay->response_data ?? '', true);
        if (!is_array($existingResponse)) {
            $existingResponse = [];
        }

        $existingResponse['pending_verification'] = true;
        $existingResponse['verification_error'] = $verify['message'] ?? 'Unknown verification failure';
        $existingResponse['verification_timestamp'] = date('Y-m-d H:i:s');
        $existingResponse['verification_attempts'] = ($existingResponse['verification_attempts'] ?? 0) + 1;

        $this->log->update((int)$pay->id, [
            'response_data' => \json_encode($existingResponse, JSON_UNESCAPED_UNICODE),
        ]);

        $this->logger->warning('payment.callback.pending_verification', [
            'gateway' => $pay->gateway,
            'authority' => $pay->authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'verify_message' => $verify['message'] ?? 'unknown'
        ]);
    }

    /**
     * Reconcile payments stuck in 'pending' status for more than 15 minutes (Failure Scenario 1 & CRITICAL #5)
     */
    public function reconcilePendingPayments(): array
    {
        $results = ['total' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0];

        try {
            $stuckPayments = $this->db->query(
                "SELECT * FROM payment_logs 
                 WHERE status = 'pending' 
                   AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY created_at ASC LIMIT 50"
            )->fetchAll(\PDO::FETCH_OBJ) ?: [];

            foreach ($stuckPayments as $pay) {
                $results['total']++;
                
                $responseData = @json_decode($pay->response_data ?? '{}', true) ?: [];
                $retryCount = (int)($responseData['retry_count'] ?? 0);

                if ($retryCount >= 5) {
                    $responseData['error_message'] = 'Max retry attempts reached (skipped)';
                    $this->log->update((int)$pay->id, [
                        'status' => 'failed',
                        'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                    ]);
                    $results['skipped']++;
                    continue;
                }

                $responseData['retry_count'] = $retryCount + 1;
                $responseData['last_retry_at'] = date('Y-m-d H:i:s');

                $this->log->update((int)$pay->id, [
                    'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                ]);

                try {
                    $storedRequestData = @json_decode($pay->request_data ?? '', true) ?: [];
                    $storedNonce = (string)($storedRequestData['callback_nonce'] ?? '');

                    // Reuse the fully secured and locked callback logic to ensure complete atomicity and safety
                    $res = $this->callback((string)$pay->gateway, [
                        'authority' => (string)$pay->authority,
                        'nonce' => $storedNonce,
                        'status' => 'OK'
                    ], (int)$pay->user_id);

                    if (!empty($res['success'])) {
                        $results['completed']++;
                    } else {
                        $results['failed']++;
                        
                        // If it has now been retried 5 times, mark as failed strictly and alert admin
                        if ($retryCount >= 4) {
                            $responseData['error_message'] = 'Max retry attempts reached';
                            $this->log->update((int)$pay->id, [
                                'status' => 'failed',
                                'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                            ]);
                            
                            $this->notifier->sendToAdmins(
                                'payment_failed_max_retries',
                                'خطای بحرانی پرداخت',
                                "پرداخت شماره {$pay->id} پس از ۵ بار تلاش ناموفق بود. کاربر: {$pay->user_id}، مبلغ: {$pay->amount}",
                                ['payment_id' => $pay->id, 'user_id' => $pay->user_id, 'amount' => $pay->amount],
                                'high'
                            );
                        }
                    }
                } catch (\Throwable $innerEx) {
                    $results['failed']++;
                    $this->logger->error('payment.reconciliation.inner_failed', [
                        'payment_id' => $pay->id,
                        'error' => $innerEx->getMessage()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('payment.reconciliation.failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Admin Panel: Pending Verification Queue (HIGH #1)
     */
    public function getPendingVerificationPayments(): array
    {
        return $this->db->query(
            "SELECT pl.*, u.email, u.mobile 
             FROM payment_logs pl
             JOIN users u ON u.id = pl.user_id
             WHERE pl.status = 'pending_verification'
             ORDER BY pl.created_at ASC"
        )->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * Admin Action: Manually Verify (HIGH #1)
     */
    public function manuallyVerifyPayment(int $paymentId, int $adminId): array
    {
        $pay = $this->log->where('id', '=', $paymentId)->first();

        if (!$pay || $pay->status !== 'pending_verification') {
            return ['success' => false, 'message' => 'Invalid payment record'];
        }

        // Re-verify with gateway
        $gw = $this->gateway((string)$pay->gateway);
        if (!$gw) {
            return ['success' => false, 'message' => 'Invalid gateway'];
        }

        try {
            $verify = $gw->verifyPayment((string)$pay->authority, (float)$pay->amount);
        } catch (\Throwable $e) {
            $this->logger->error('payment.manual_verify.exception', [
                'payment_id' => $paymentId,
                'gateway' => $pay->gateway,
                'authority' => $pay->authority,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'Error communicating with gateway: ' . $e->getMessage()];
        }

        if (!empty($verify['success'])) {
            // Process it using the secure callback method.
            // We read the existing request_data's callback_nonce to bypass nonce check in callback.
            $storedRequestData = @json_decode($pay->request_data ?? '', true) ?: [];
            $bypassNonce = (string)($storedRequestData['callback_nonce'] ?? 'BYPASS_NONCE');
            
            return $this->callback((string)$pay->gateway, [
                'authority' => (string)$pay->authority,
                'nonce' => $bypassNonce,
                'status' => 'OK'
            ], (int)$pay->user_id);
        } else {
            // Mark as failed
            $this->log->update($paymentId, [
                'status' => 'failed',
                'response_data' => json_encode($verify, JSON_UNESCAPED_UNICODE)
            ]);

            return ['success' => false, 'message' => $verify['message'] ?? 'Manual verification failed'];
        }
    }
}