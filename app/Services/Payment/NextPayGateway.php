<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use Core\CircuitBreaker;
use App\Exceptions\PaymentVerificationException;

/**
 * NextPayGateway - درگاه نکست‌پی
 * 
 * یک درگاه پیمنٹ ایرانی سریع و قابل اعتماد۔
 * 
 * نوٹ: Amount Rial میں ہے لیکن NextPay Toman میں چاہتا ہے (divide by 10)
 * 
 * Retry Strategy:
 * - Connection timeouts: Retry 3x with exponential backoff
 * - Server errors (5xx): Retry 3x with exponential backoff
 * - Invalid API key (4xx): Do NOT retry
 */
class NextPayGateway extends BasePaymentGateway
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
        $this->config = $paymentGatewayModel->getActiveGateway('nextpay');
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
                'message' => 'درگاه نکست‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if ($amount <= 0) {
            $this->logger->warning('payment.nextpay.invalid_amount', ['amount' => $amount]);
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        // NextPay expects amount in Toman, input is in Toman
        // Conversion: Toman → Toman (No conversion needed)
        $data = [
            'api_key' => $this->config->api_key,
            'amount' => (int)$amount, // NextPay requires Toman
            'order_id' => \uniqid('nextpay_'),
            'callback_uri' => $callbackUrl,
            'customer_phone' => $options['mobile'] ?? '',
        ];

        $url = 'https://nextpay.org/nx/gateway/token';

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff (Using explicitly mapped 'form' payload type)
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', [], 'form');

            // NextPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه'
                ];
            }

            $result = $response['data'];

            if (isset($result['code']) && $result['code'] == -1) {
                $transId = $result['trans_id'];
                $this->logger->info('payment.nextpay.payment_created', [
                    'trans_id' => $transId,
                    'amount_toman' => (int)$amount
                ]);

                return [
                    'success' => true,
                    'authority' => $transId,
                    'url' => "https://nextpay.org/nx/gateway/payment/{$transId}",
                    'message' => 'موفق'
                ];
            }

            return [
                'success' => false,
                'message' => 'خطا در ایجاد تراکنش'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.nextpay.connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه (بعد از تلاش مجدد)'
            ];
        } catch (\Exception $e) {
            $this->logger->error('payment.nextpay.request_failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه نکست‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if (empty($authority)) {
            throw new \InvalidArgumentException('Authority cannot be empty');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        // NextPay expects amount in Toman, input is in Toman
        $data = [
            'api_key' => $this->config->api_key,
            'trans_id' => $authority,
            'amount' => (int)$amount, // Toman amount for verification
        ];

        $url = 'https://nextpay.org/nx/gateway/verify';

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', [], 'form');

            // NextPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = $response['data'];

            if (isset($result['code']) && $result['code'] == 0) {
                $this->logger->info('payment.nextpay.verified', [
                    'authority' => $authority,
                    'ref_id' => $result['Shaparak_Ref_Id'] ?? 'unknown'
                ]);

                return [
                    'success' => true,
                    'ref_id' => $result['Shaparak_Ref_Id'] ?? $authority,
                    'amount' => isset($result['amount']) ? (float)$result['amount'] : $amount,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => 'تراکنش ناموفق'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.nextpay.verification_connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت (خطای شبکه)'
            ];
        } catch (PaymentVerificationException $e) {
            $this->logger->warning('payment.nextpay.verification_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        } catch (\Exception $e) {
            $this->logger->error('payment.nextpay.verify_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function refundPayment(string $authority): array
    {
        // NextPay refund support - implement if needed
        return [
            'success' => false,
            'message' => 'بازگشت پرداخت در این درگاه پشتیبانی نمی‌شود'
        ];
    }

    public function getName(): string
    {
        return 'nextpay';
    }

    public function getGatewayName(): string
    {
        return 'nextpay';
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}