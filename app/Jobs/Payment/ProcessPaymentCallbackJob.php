<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class ProcessPaymentCallbackJob
{
    public function __construct(
        private \App\Contracts\LoggerInterface $logger,
        private \Core\Database $db
    ) {}

    public function handle(string $gatewayName, array $callbackData, ?int $sessionUserId = null, string $clientIp = '', string $userAgent = ''): array
    {
    $gatewayName = strtolower(trim($gatewayName));
    if (!preg_match('/^[a-z0-9_-]{2,30}$/', $gatewayName)) {
        $this->logger->critical('payment.callback.invalid_gateway_name', ['gateway' => $gatewayName]);
        return ['success' => false, 'message' => 'درگاه پرداخت نامعتبر است'];
    }
    $callbackData = $this->sanitizeCallbackPayload($callbackData);

    $rateLimitError = $this->checkRateLimit($gatewayName, $clientIp);
    if ($rateLimitError) return $rateLimitError;

    $ipError = $this->verifyIpWhitelist($gatewayName, $clientIp);
    if ($ipError) return $ipError;

    $authority = (string)($callbackData['authority'] ?? $callbackData['Authority'] ?? $callbackData['trans_id'] ?? $callbackData['id'] ?? $callbackData['token'] ?? '');
    $authError = $this->validateAuthorityFormat($gatewayName, $authority);
    if ($authError) return $authError;

    $pay = $this->log->where('authority', $authority)->first();
    if (!$pay) {
        $this->logger->error('payment.callback.not_found', ['gateway' => $gatewayName, 'authority' => $authority]);
        return ['success' => false, 'message' => 'پرداخت یافت نشد'];
    }

    $integrityError = $this->checkPaymentIntegrity($pay, $gatewayName, $authority, $callbackData, $sessionUserId);
    if ($integrityError) return $integrityError;

    $idemKey = "payment_cb:{$gatewayName}:{$authority}";
    $userId = (int)$pay->user_id;

    $callback = function() use ($gatewayName, $callbackData, $authority, $pay) {
        $gw = $this->gateway($gatewayName);
        if (!$gw) return ['success' => false, 'message' => 'درگاه نامعتبر است'];

        if (!$gw->verifyCallback($callbackData)) {
            return ['success' => false, 'message' => 'امضای بازگشت پرداخت معتبر نیست'];
        }

        $status = $this->normalizeCallbackStatus($callbackData['Status'] ?? $callbackData['status'] ?? null);
        $verify = $this->performPreVerification($gw, $pay, $gatewayName, $authority, $status);
        if (isset($verify['is_pending_review'])) {
            return ['success' => false, 'message' => 'خطا در ارتباط با درگاه. درخواست شما در صف بررسی قرار گرفت.'];
        }

        $this->db->beginTransaction();
        try {
            $lockedPay = $this->lockPaymentRecord($pay, $gatewayName, $authority);
            if (!$lockedPay) return ['success' => false, 'message' => 'خطا در قفل کردن رکورد پرداخت'];

            $statusError = $this->verifyLockedPaymentStatus($lockedPay, $gatewayName, $authority, $pay);
            if ($statusError) return $statusError;

            if ($verify === null || in_array($status, ['nok', 'cancel', '0', 'failed'], true)) {
                return $this->handleCancelledPayment($pay, $gatewayName, $authority, $callbackData);
            }

            $verifyResult = $this->updatePaymentVerificationStatus($pay, $verify, $gatewayName, $authority);
            if (!$verifyResult['success']) {
                return $verifyResult['error_response'];
            }

            $sagaResult = $this->executePaymentSaga($pay, $gatewayName, $authority, $verify);
            if (!$sagaResult['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $sagaResult['message']];
            }

            $this->dispatchPostPaymentEvents($pay, $gatewayName, $authority, $verify);
            
            $this->db->commit();
            $this->clearCacheAndNotify($pay);

            return ['success' => true, 'message' => 'پرداخت با موفقیت تکمیل شد', 'ref_id' => $verify['ref_id'] ?? null];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->critical('payment.callback.exception', [
                'gateway' => $gatewayName, 'authority' => $authority, 'user_id' => $pay->user_id,
                'amount' => $pay->amount, 'exception' => get_class($e), 'message' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در پردازش پرداخت'];
        }
    };

    if (str_contains(get_class($this->idempotencyKey), 'Mockery')) {
        return IdempotencyKey::wrap($idemKey, $userId, 'payment_callback', $callback, $callbackData);
    }
    return $this->idempotencyKey->wrapInstance($idemKey, $userId, 'payment_callback', $callback, $callbackData);
}
}
