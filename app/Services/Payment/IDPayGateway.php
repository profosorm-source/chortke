<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Contracts\PaymentGatewayInterface;

class IDPayGateway implements PaymentGatewayInterface
{
    private \App\Models\PaymentGateway $paymentGatewayModel;
    private ?object $config;
    protected LoggerInterface $logger;

    public function __construct(
        \App\Models\PaymentGateway $paymentGatewayModel,
        LoggerInterface            $logger
    ) {
        $this->paymentGatewayModel = $paymentGatewayModel;
        $this->config = $paymentGatewayModel->getActiveGateway('idpay');
        $this->logger = $logger;
    }

    public function createPayment(float $amount, string $description, string $callbackUrl): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه آیدی‌پی غیرفعال است'
            ];
        }

        $data = [
            'order_id' => \uniqid('idpay_'),
            'amount' => $amount / 10, // آیدی‌پی تومان می‌خواهد
            'desc' => $description,
            'callback' => $callbackUrl,
        ];

        $url = 'https://api.idpay.ir/v1.1/payment';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY: ' . $this->config->api_key,
                'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 201) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['id']) && isset($result['link'])) {
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

        } catch (\Exception $e) {
            $this->logger->error('payment.idpay.request_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه'
            ];
        }
    }

    public function verifyPayment(string $authority, float $amount): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه آیدی‌پی غیرفعال است'
            ];
        }

        $data = [
            'id' => $authority,
            'order_id' => \uniqid('idpay_'),
        ];

        $url = 'https://api.idpay.ir/v1.1/payment/verify';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY: ' . $this->config->api_key,
                'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['status']) && $result['status'] == 100) {
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

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}