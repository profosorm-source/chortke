<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use App\Exceptions\PaymentVerificationException;
use Core\CircuitBreaker;

/**
 * ZarinPalGateway - درگاه زرین‌پال
 * 
 * یک درگاه پیمنٹ ایرانی جو ریئل ٹائم میں پرداخت کی سہولت دیتا ہے۔
 * 
 * Retry Strategy:
 * - Connection timeouts: Retry 3x with exponential backoff
 * - Server errors (5xx): Retry 3x with exponential backoff
 * - Client errors (4xx): Do NOT retry
 */
class ZarinPalGateway extends BasePaymentGateway
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
        $this->config = $paymentGatewayModel->getActiveGateway('zarinpal');
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
     * - Invalid amount: ❌ Do not retry
     */
    public function createPayment(float $amount, string $description, string $callbackUrl, array $options = []): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه زرین‌پال غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if ($amount <= 0) {
            $this->logger->warning('payment.zarinpal.invalid_amount', ['amount' => $amount]);
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        $data = [
            'merchant_id' => $this->config->merchant_id,
            'amount' => $amount,
            'description' => $description,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'mobile' => (string)($options['mobile'] ?? ''),
                'email' => (string)($options['email'] ?? '')
            ]
        ];

        $url = $this->config->is_test_mode 
            ? config('payment.zarinpal.sandbox_url') . '/PaymentRequest.json'
            : config('payment.zarinpal.api_url') . '/request.json';

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST');

            if (!$response['success'] || $response['http_code'] !== 200) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه'
                ];
            }

            $result = $response['data'];

            if (isset($result['data']['code']) && $result['data']['code'] == 100) {
                $authority = $result['data']['authority'];
                $paymentUrl = $this->config->is_test_mode
                    ? config('payment.zarinpal.sandbox_pay_url') . "/{$authority}"
                    : config('payment.zarinpal.payment_url') . "/{$authority}";

                $this->logger->info('payment.zarinpal.payment_created', [
                    'authority' => $authority,
                    'amount' => $amount
                ]);

                return [
                    'success' => true,
                    'authority' => $authority,
                    'url' => $paymentUrl,
                    'message' => 'موفق'
                ];
            }

            return [
                'success' => false,
                'message' => $result['errors']['message'] ?? 'خطای نامشخص'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.zarinpal.connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه (بعد از تلاش مجدد)'
            ];
        } catch (\Exception $e) {
            $this->logger->error('payment.zarinpal.request_failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه زرین‌پال غیرفعال است'
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
            'merchant_id' => $this->config->merchant_id,
            'authority' => $authority,
            'amount' => $amount,
        ];

        $url = $this->config->is_test_mode
            ? config('payment.zarinpal.sandbox_url') . '/PaymentVerification.json'
            : config('payment.zarinpal.api_url') . '/verify.json';

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST');

            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = $response['data'];

            // 🕵️ Strictly verify existence of ref_id ensuring settlement security
            // H24 Fix (Problem 2): پذیرفتن کد 101 (قبلاً تایید شده) جهت جلوگیری از سوخت شدن پول در صورت Timeout تراکنش اول
            $code = isset($result['data']['code']) ? (int)$result['data']['code'] : 0;
            if (in_array($code, [100, 101]) && isset($result['data']['ref_id']) && !empty($result['data']['ref_id'])) {
                $this->logger->info('payment.zarinpal.verified', [
                    'authority' => $authority,
                    'ref_id' => $result['data']['ref_id']
                ]);

                return [
                    'success' => true,
                    'ref_id' => (string)$result['data']['ref_id'],
                    'amount' => isset($result['data']['amount']) ? (float)$result['data']['amount'] : $amount,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => $result['errors']['message'] ?? 'تراکنش ناموفق یا شناسه مرجع نامعتبر'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.zarinpal.verification_connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت (خطای شبکه)'
            ];
        } catch (PaymentVerificationException $e) {
            $this->logger->warning('payment.zarinpal.verification_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        } catch (\Exception $e) {
            $this->logger->error('payment.zarinpal.verify_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function refundPayment(string $authority): array
    {
        // ZarinPal refund support - implement if needed
        return [
            'success' => false,
            'message' => 'بازگشت پرداخت در این درگاه پشتیبانی نمی‌شود'
        ];
    }

    public function getName(): string
    {
        return 'zarinpal';
    }

    public function getGatewayName(): string
    {
        return 'zarinpal';
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}