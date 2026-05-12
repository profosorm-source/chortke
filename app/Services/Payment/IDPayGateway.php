<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use App\Exceptions\PaymentVerificationException;

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
    private \App\Services\SettingService $settingService;

    public function __construct(
        \App\Models\PaymentGateway   $paymentGatewayModel,
        LoggerInterface              $logger,
        \App\Services\SettingService $settingService
    ) {
        parent::__construct($logger);
        $this->paymentGatewayModel = $paymentGatewayModel;
        $this->settingService      = $settingService;
        $this->config = $paymentGatewayModel->getActiveGateway('idpay');
    }

    /**
     * نیا پیمنٹ بنائیں
     * 
     * Retry Logic:
     * - Connection timeouts: ✅ Retry
     * - Server errors (5xx): ✅ Retry
     * - Invalid API key: ❌ Do not retry
     */
    public function createPayment(float $amount, string $description, string $callbackUrl): array
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

        // IDPay expects amount in Toman, input is in Rial
        // Conversion: Rial → Toman (1 Toman = 10 Rial)
        $data = [
            'order_id' => \uniqid('idpay_'),
            'amount' => (int)($amount / 10), // IDPay requires Toman (divide Rial by 10)
            'desc' => $description,
            'callback' => $callbackUrl,
        ];

        $url = 'https://api.idpay.ir/v1.1/payment';

        $headers = [
            'X-API-KEY: ' . $this->config->api_key,
            'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
        ];

        try {
            // 🔄 Execute with retry and exponential backoff
            $response = $this->executeWithRetry($url, $data, 'POST', $headers);

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
                    'amount_toman' => (int)($amount / 10)
                ]);

                return [
                    'success' => true,
                    'authority' => $result['id'],
                    'url' => $result['link'],
                    'message' => 'موفق'
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

        $data = [
            'id' => $authority,
            'order_id' => \uniqid('idpay_'),
        ];

        $url = 'https://api.idpay.ir/v1.1/payment/verify';

        $headers = [
            'X-API-KEY: ' . $this->config->api_key,
            'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
        ];

        try {
            // 🔄 Execute with retry and exponential backoff
            $response = $this->executeWithRetry($url, $data, 'POST', $headers);

            // IDPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = $response['data'];

            if (isset($result['status']) && $result['status'] == 100) {
                $this->logger->info('payment.idpay.verified', [
                    'authority' => $authority,
                    'track_id' => $result['track_id'] ?? 'unknown'
                ]);

                return [
                    'success' => true,
                    'ref_id' => $result['track_id'] ?? $authority,
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