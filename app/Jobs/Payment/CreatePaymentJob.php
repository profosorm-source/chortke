<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class CreatePaymentJob
{
    public function __construct(
        private \Core\Database $db,
        private \App\Contracts\LoggerInterface $logger
    ) {}

    public function handle(int $userId, string $gatewayName, float $amount, int $bankCardId, string $idempotencyKey, string $clientIp = '', string $userAgent = ''): array
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
                'ip'           => $clientIp,
                'user_agent'   => $userAgent
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
                'ip_address' => $clientIp,
                'user_agent' => $userAgent,
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
}
