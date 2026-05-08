<?php

namespace App\Services\Payment;

use App\Models\BankCard;
use App\Models\PaymentLog;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\LoggerInterface;
use App\Services\Payment\PaymentGatewayFactory;
use Core\IdempotencyKey;
use Core\Exceptions\ValidationException;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\BusinessException;
use App\Services\CurrencyService;

class PaymentService extends PaymentBaseService
{
    private \App\Models\BankCard $bankCardModel;
    private PaymentLog $log;
    private WalletService $wallet;
    private NotificationService $notifier;
	private IdempotencyKey $idempotencyKey;
    private PaymentGatewayFactory $gatewayFactory;
    private CurrencyService $currencyService;

    public function __construct(
        WalletService $walletService,
        NotificationService $notificationService,
        \App\Models\PaymentLog $log,
        \App\Models\BankCard $bankCardModel,
        LoggerInterface $logger,
        IdempotencyKey $idempotencyKey,
        PaymentGatewayFactory $gatewayFactory,
        CurrencyService $currencyService
    ) {
        parent::__construct($logger);
        $this->log = $log;
        $this->wallet = $walletService;
        $this->notifier = $notificationService;
        $this->bankCardModel = $bankCardModel;
        $this->idempotencyKey = $idempotencyKey;
        $this->gatewayFactory = $gatewayFactory;
        $this->currencyService = $currencyService;
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
    public function create(int $userId, string $gatewayName, float $amount, int $bankCardId): array
    {
        $this->logStart('create', [
            'user_id' => $userId,
            'gateway' => $gatewayName,
            'amount' => $amount,
            'bank_card_id' => $bankCardId
        ]);

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

        $callback = url('/payment/callback/' . $gatewayName);
        $desc = 'شارژ کیف پول چرتکه';

        try {
            $res = $gw->createPayment($amount, $desc, $callback);
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
            'request_data' => \json_encode(['amount'=>$amount,'callback'=>$callback], JSON_UNESCAPED_UNICODE),
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
    }
/**
 * Callback پرداخت آنلاین
 * 
 * فایل: app/Services/PaymentService.php
 * خط: ~85
 */
public function callback(string $gatewayName, array $callbackData): array
{
    // ابتدا authority را بررسی می‌کنیم
    $authority = (string)($callbackData['authority'] ?? $callbackData['Authority'] ?? '');
    if ($authority === '') {
        return ['success' => false, 'message' => 'authority نامعتبر است'];
    }

    // برای جلوگیری از پردازش دوباره از idempotency key استفاده می‌کنیم
    $idemKey = "payment_cb:{$gatewayName}:{$authority}";
    $idem = $this->idempotencyKey->check($idemKey, 0, 'payment_callback', $callbackData);
if (!empty($idem['is_duplicate'])) {
    return $idem['result'] ?? ['success' => true, 'message' => 'پرداخت قبلاً پردازش شده'];
}

    // لاگ گرفتن از درخواست دریافتی
    $this->logger->info('payment.callback.received', [
        'gateway' => $gatewayName,
        'callback_data' => $callbackData
    ]);
    
    // بررسی صحت درگاه پرداخت
    $gw = $this->gateway($gatewayName);
    if (!$gw) {
        $this->logger->error('payment.callback.invalid_gateway', [
            'gateway' => $gatewayName
        ]);
        return ['success' => false, 'message' => 'درگاه نامعتبر است'];
    }

    // دریافت authority از callbackData
    $authority = (string)($callbackData['authority'] ?? $callbackData['Authority'] ?? '');
if ($authority === '') {
    $authority = (string)($callbackData['trans_id'] ?? $callbackData['id'] ?? $callbackData['token'] ?? '');
}
if ($authority === '') {
    return ['success' => false, 'message' => 'کد رهگیری یافت نشد'];
}

    // جستجو برای پرداخت
    $pay = $this->log->where('authority', $authority)->first();
    if (!$pay) {
        $this->logger->error('payment.callback.not_found', [
            'gateway' => $gatewayName,
            'authority' => $authority
        ]);
        return ['success' => false, 'message' => 'پرداخت یافت نشد'];
    }

    // بررسی وضعیت پرداخت
    if ($pay->status === 'completed') {
        $this->logger->warning('payment.callback.already_completed', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'ref_id' => $pay->ref_id
        ]);
        return ['success' => true, 'message' => 'این پرداخت قبلاً تکمیل شده است', 'ref_id' => $pay->ref_id];
    }

    // بررسی وضعیت پرداخت (لغو یا عدم تایید)
    $status = $callbackData['Status'] ?? $callbackData['status'] ?? null;
    if ($status === 'NOK' || $status === 'cancel' || $status === 0) {
        $this->log->update((int)$pay->id, [
            'status' => 'cancelled',
            'response_data' => \json_encode($callbackData, JSON_UNESCAPED_UNICODE),
        ]);
        
        $this->logger->info('payment.callback.cancelled', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount
        ]);
        
        return ['success' => false, 'message' => 'پرداخت لغو شد'];
    }

    // عملیات تأیید پرداخت
    try {
        $verify = $gw->verifyPayment($authority, (float)$pay->amount);
    } catch (\Exception $e) {
        $this->logger->critical('payment.verify.exception', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'exception' => get_class($e),
            'message' => $e->getMessage()
        ]);
        
        $this->log->update((int)$pay->id, [
            'status' => 'failed',
            'response_data' => \json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
        ]);
        
        return ['success' => false, 'message' => 'خطا در تأیید پرداخت'];
    }

    // به‌روزرسانی وضعیت پرداخت در سیستم
    $this->log->update((int)$pay->id, [
        'status' => $verify['success'] ? 'verified' : 'failed',
        'ref_id' => $verify['ref_id'] ?? null,
        'paid_at' => $verify['success'] ? date('Y-m-d H:i:s') : null,
        'response_data' => \json_encode($verify, JSON_UNESCAPED_UNICODE),
    ]);

    // در صورتی که پرداخت تأیید نشده باشد
    if (!$verify['success']) {
        $this->logger->error('payment.verify.failed', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'verify_message' => $verify['message'] ?? 'unknown'
        ]);
        return ['success' => false, 'message' => $verify['message'] ?? 'تأیید پرداخت ناموفق'];
    }

    // واریز مبلغ به کیف پول
    try {
        $ok = $this->wallet->deposit(
            (int) $pay->user_id,        // 1. userId
            (float) $pay->amount,        // 2. amount
            'irt',                       // 3. currency (lowercase)
            [                            // 4. metadata
                'type'                  => 'gateway_deposit',
                'gateway'               => $gatewayName,
                'authority'             => $authority,
                'ref_id'                => $verify['ref_id'] ?? null,
                'description'           => 'واریز آنلاین (درگاه)'
            ]
        );
    } catch (\Exception $e) {
        $this->logger->critical('payment.wallet_deposit.exception', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'ref_id' => $verify['ref_id'] ?? null,
            'exception' => get_class($e),
            'message' => $e->getMessage()
        ]);
        
        return [
            'success' => false,
            'message' => 'پرداخت تأیید شد اما خطا در شارژ کیف پول رخ داد، با پشتیبانی تماس بگیرید'
        ];
    }

    // چک کردن موفقیت شارژ کیف پول
    if (!$ok['success']) {
        $this->logger->error('payment.wallet_deposit.failed', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'ref_id' => $verify['ref_id'] ?? null,
            'wallet_message' => $ok['message'] ?? 'unknown'
        ]);
        
        return [
            'success' => false,
            'message' => 'پرداخت تأیید شد اما شارژ کیف پول ناموفق بود، با پشتیبانی تماس بگیرید'
        ];
    }

    // تغییر وضعیت پرداخت به تکمیل شده
    $this->log->update((int)$pay->id, ['status' => 'completed']);
    $this->logger->info('payment.completed', [
        'gateway' => $gatewayName,
        'authority' => $authority,
        'user_id' => $pay->user_id,
        'amount' => $pay->amount,
        'ref_id' => $verify['ref_id'] ?? null
    ]);

    // ثبت نتیجه نهایی در idempotency key
    $this->idempotencyKey->complete($idemKey, [
        'success' => true,
        'message' => 'پرداخت تایید شد'
    ], (int)$pay->user_id);

    // نوتیفیکیشن موفقیت پرداخت
    $this->notifier->depositSuccess((int)$pay->user_id, (float)$pay->amount, 'IRT');

    return [
        'success' => true,
        'message' => 'پرداخت موفق و کیف پول شارژ شد',
        'ref_id'  => $verify['ref_id'] ?? null
    ];
}
}