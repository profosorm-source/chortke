<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use Core\CircuitBreaker;
use App\Exceptions\PaymentVerificationException;

/**
 * DgPayGateway - درگاه دی‌جی‌پی
 * 
 * یک درگاه پیمنٹ ایرانی قابل اعتماد۔
 * 
 * نوٹ: Amount Rial میں ہے لیکن DgPay Toman میں چاہتا ہے (divide by 10)
 * 
 * Retry Strategy:
 * - Connection timeouts: Retry 3x with exponential backoff
 * - Server errors (5xx): Retry 3x with exponential backoff
 * - Invalid merchant key (4xx): Do NOT retry
 */
class DgPayGateway extends BasePaymentGateway
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
        $this->config = $paymentGatewayModel->getActiveGateway('dgpay');
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
     * - Invalid merchant key: ❌ Do not retry
     */
    public function createPayment(float $amount, string $description, string $callbackUrl, array $options = []): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه دی‌جی‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if ($amount <= 0) {
            $this->logger->warning('payment.dgpay.invalid_amount', ['amount' => $amount]);
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        // DgPay expects amount in Rial, input is in Toman (IRT)
        // Conversion: Toman → Rial (1 Toman = 10 Rial)
        $data = [
            'merchant' => $this->config->merchant_id,
            'amount' => (int)($amount * 10), // DgPay requires Rial (multiply Toman by 10)
            'description' => $description,
            'callback' => $callbackUrl,
            'mobile' => $options['mobile'] ?? '',
        ];

        $url = 'https://dgpay.ir/api/v1/payment/request';

        $headers = [
            'Content-Type: application/json',
        ];

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', $headers);

            // DgPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه'
                ];
            }

            $result = $response['data'];

            if (isset($result['status']) && $result['status'] === 'success') {
                $this->logger->info('payment.dgpay.payment_created', [
                    'token' => $result['token'],
                    'amount_toman' => (int)$amount
                ]);

                return [
                    'success' => true,
                    'authority' => $result['token'],
                    'url' => "https://dgpay.ir/payment/{$result['token']}",
                    'message' => 'موفق'
                ];
            }

            return [
                'success' => false,
                'message' => $result['message'] ?? 'خطای نامشخص'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.dgpay.connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه (بعد از تلاش مجدد)'
            ];
        } catch (\Exception $e) {
            $this->logger->error('payment.dgpay.request_failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه دی‌جی‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if (empty($authority)) {
            throw new \InvalidArgumentException('Authority cannot be empty');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        // 🕵️ Extended Matching: Safely forward amount (normalized to Rial) to prevent potential gateway mismatch
        $data = [
            'merchant' => $this->config->merchant_id,
            'token' => $authority,
            'amount' => (int)($amount * 10)
        ];

        $url = 'https://dgpay.ir/api/v1/payment/verify';

        $headers = [
            'Content-Type: application/json',
        ];

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', $headers);

            // DgPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = $response['data'];

            if (isset($result['status']) && $result['status'] === 'success') {
                $this->logger->info('payment.dgpay.verified', [
                    'authority' => $authority,
                    'ref_id' => $result['ref_id'] ?? 'unknown'
                ]);

                return [
                    'success' => true,
                    'ref_id' => $result['ref_id'] ?? $authority,
                    'amount' => isset($result['amount']) ? ((float)$result['amount'] / 10) : $amount,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => $result['message'] ?? 'تراکنش ناموفق'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.dgpay.verification_connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت (خطای شبکه)'
            ];
        } catch (PaymentVerificationException $e) {
            $this->logger->warning('payment.dgpay.verification_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        } catch (\Exception $e) {
            $this->logger->error('payment.dgpay.verify_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function refundPayment(string $authority): array
    {
        // DgPay refund support - implement if needed
        return [
            'success' => false,
            'message' => 'بازگشت پرداخت در این درگاه پشتیبانی نمی‌شود'
        ];
    }

    public function getName(): string
    {
        return 'dgpay';
    }

    public function getGatewayName(): string
    {
        return 'dgpay';
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}