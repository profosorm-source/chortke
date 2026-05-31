<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use App\Exceptions\PaymentVerificationException;
use Core\CircuitBreaker;

/**
 * IDPayGateway - درگاه آیدی‌پی
 * 
 * یک درگاه پیمنٹ ایرانی دوم جو سریع ترین رفع العمل کے ساتھ جانا جاتا ہے۔
 * 
 * نوٹ: Amount Rial میں ہے لیکن IDPay Toman میں چاہتا ہے (divide by 10)
 * 
 * Retry Strategy:
 * - Connection timeouts: Retry 3x with exponential backoff
 * - Server errors (5xx): Retry 3x with exponential backoff
 * - Invalid API key (4xx): Do NOT retry
 */
class IDPayGateway extends BasePaymentGateway
{
    private \App\Models\PaymentGateway $paymentGatewayModel;
    private ?object $config;
    private \App\Services\Settings\AppSettings $appSettings;

    public function __construct(
        \App\Models\PaymentGateway   $paymentGatewayModel,
        \App\Services\Settings\AppSettings $appSettings,
        CircuitBreaker               $circuitBreaker
    ) {
        parent::__construct($context, $circuitBreaker);
        $this->paymentGatewayModel = $paymentGatewayModel;
        $this->appSettings = $appSettings;
        $this->config = $paymentGatewayModel->getActiveGateway('idpay');
    }

    protected function getGatewayConfig(): ?object
    {
        return $this->config;
    }

    /**
     * نیا پیمنٹ بنائیں
     * 
     * Retry Logic:
     * - Connection timeouts: ✅ Retry
     * - Server errors (5xx): ✅ Retry
     * - Invalid API key: ❌ Do not retry
     */
    public function createPayment(float $amount, string $description, string $callbackUrl, array $options = []): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه آیدی‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if ($amount <= 0) {
            $this->logger->warning('payment.idpay.invalid_amount', ['amount' => $amount]);
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        $orderId = \uniqid('idpay_');

        // IDPay expects amount in Rial, input is in Toman (IRT)
        // Conversion: Toman → Rial (1 Toman = 10 Rial)
        $data = [
            'order_id' => $orderId,
            'amount' => (int)($amount * 10), // IDPay requires Rial (multiply Toman by 10)
            'desc' => $description,
            'callback' => $callbackUrl,
            'phone' => $options['mobile'] ?? $options['phone'] ?? '',
            'mail' => $options['email'] ?? '',
        ];

        $url = 'https://api.idpay.ir/v1.1/payment';

        $headers = [
            'X-API-KEY: ' . $this->config->api_key,
            'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
        ];

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', $headers);

            // IDPay returns 201 on success
            if (!$response['success'] || $response['http_code'] !== 201) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه'
                ];
            }

            $result = $response['data'];

            if (isset($result['id']) && isset($result['link'])) {
                $this->logger->info('payment.idpay.payment_created', [
                    'id' => $result['id'],
                    'amount_toman' => (int)$amount
                ]);

                return [
                    'success' => true,
                    'authority' => (string)$result['id'],
                    'url' => (string)$result['link'],
                    'message' => 'موفق',
                    // 💾 Save order_id to ensure verification retrieves and sends the SAME order_id
                    'order_id' => $orderId 
                ];
            }

            return [
                'success' => false,
                'message' => $result['error_message'] ?? 'خطای نامشخص'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.idpay.connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه (بعد از تلاش مجدد)'
            ];
        } catch (\Exception $e) {
            $this->logger->error('payment.idpay.request_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه'
            ];
        }
    }

    /**
     * پرداخت کی تصدیق کریں
     * 
     * Retry Logic:
     * - Connection timeouts: ✅ Retry
     * - Server errors (5xx): ✅ Retry
     * - Invalid transaction: ❌ Do not retry
     */
    public function verifyPayment(string $authority, float $amount): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه آیدی‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if (empty($authority)) {
            throw new \InvalidArgumentException('Authority cannot be empty');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        // 🕵️ Stateful Matching: Retrieve the EXACT same order_id originally sent during createPayment
        $orderId = null;
        try {
            $log = $this->paymentGatewayModel->getDb()->table('payment_logs')
                ->where('authority', '=', $authority)
                ->first();

            if ($log && !empty($log->response_data)) {
                $savedRes = \json_decode((string)$log->response_data, true);
                $orderId = $savedRes['order_id'] ?? null;
            }
        } catch (\Throwable $e) {
            $this->logger->error('payment.idpay.order_id_lookup_exception', ['error' => $e->getMessage()]);
        }

        // Failover to current tracking ID if historical record lookup falls short
        if (!$orderId) {
            $orderId = $authority;
        }

        $data = [
            'id' => $authority,
            'order_id' => $orderId,
        ];

        $url = 'https://api.idpay.ir/v1.1/payment/verify';

        $headers = [
            'X-API-KEY: ' . $this->config->api_key,
            'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
        ];

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', $headers);

            // IDPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = $response['data'];

            $status = isset($result['status']) ? (int)$result['status'] : 0;
            if (in_array($status, [100, 101])) {
                $this->logger->info('payment.idpay.verified', [
                    'authority' => $authority,
                    'track_id' => $result['track_id'] ?? 'unknown'
                ]);

                return [
                    'success' => true,
                    'ref_id' => $result['track_id'] ?? $authority,
                    'amount' => isset($result['amount']) ? ((float)$result['amount'] / 10) : $amount,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => $result['error_message'] ?? 'تراکنش ناموفق'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.idpay.verification_connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت (خطای شبکه)'
            ];
        } catch (PaymentVerificationException $e) {
            $this->logger->warning('payment.idpay.verification_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        } catch (\Exception $e) {
            $this->logger->error('payment.idpay.verify_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function refundPayment(string $authority): array
    {
        // IDPay refund support - implement if needed
        return [
            'success' => false,
            'message' => 'بازگشت پرداخت در این درگاه پشتیبانی نمی‌شود'
        ];
    }

    public function getName(): string
    {
        return 'idpay';
    }

    public function getGatewayName(): string
    {
        return 'idpay';
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}