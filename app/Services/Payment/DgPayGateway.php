<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Contracts\PaymentGatewayInterface;

class DgPayGateway implements PaymentGatewayInterface
{
    private \App\Models\PaymentGateway $paymentGatewayModel;
    private ?object $config;
    protected LoggerInterface $logger;

    public function __construct(
        \App\Models\PaymentGateway $paymentGatewayModel,
        LoggerInterface            $logger
    ) {
        $this->paymentGatewayModel = $paymentGatewayModel;
        $this->config = $paymentGatewayModel->getActiveGateway('dgpay');
        $this->logger = $logger;
    }

    public function createPayment(float $amount, string $description, string $callbackUrl): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه دی‌جی‌پی غیرفعال است'
            ];
        }

        // توجه: این یک نمونه است - API واقعی DgPay ممکن است متفاوت باشد
        $data = [
            'merchant' => $this->config->merchant_id,
            'amount' => $amount / 10,
            'description' => $description,
            'callback' => $callbackUrl,
        ];

        $url = 'https://dgpay.ir/api/v1/payment/request';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['status']) && $result['status'] === 'success') {
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

        } catch (\Exception $e) {
            $this->logger->error('payment.dgpay.request_failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه دی‌جی‌پی غیرفعال است'
            ];
        }

        $data = [
            'merchant' => $this->config->merchant_id,
            'token' => $authority,
        ];

        $url = 'https://dgpay.ir/api/v1/payment/verify';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['status']) && $result['status'] === 'success') {
                return [
                    'success' => true,
                    'ref_id' => $result['ref_id'] ?? $authority,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => $result['message'] ?? 'تراکنش ناموفق'
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

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}