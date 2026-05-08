<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Contracts\PaymentGatewayInterface;

class NextPayGateway implements PaymentGatewayInterface
{
    private \App\Models\PaymentGateway $paymentGatewayModel;
    private ?object $config;
    protected LoggerInterface $logger;

    public function __construct(
        \App\Models\PaymentGateway $paymentGatewayModel,
        LoggerInterface            $logger
    ) {
        $this->paymentGatewayModel = $paymentGatewayModel;
        $this->config = $paymentGatewayModel->getActiveGateway('nextpay');
        $this->logger = $logger;
    }

    public function createPayment(float $amount, string $description, string $callbackUrl): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه نکست‌پی غیرفعال است'
            ];
        }

        $data = [
            'api_key' => $this->config->api_key,
            'amount' => $amount / 10, // نکست‌پی تومان می‌خواهد
            'order_id' => \uniqid('nextpay_'),
            'callback_uri' => $callbackUrl,
        ];

        $url = 'https://nextpay.org/nx/gateway/token';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($data));

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['code']) && $result['code'] == -1) {
                $transId = $result['trans_id'];
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

        } catch (\Exception $e) {
            $this->logger->error('payment.nextpay.request_failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه نکست‌پی غیرفعال است'
            ];
        }

        $data = [
            'api_key' => $this->config->api_key,
            'trans_id' => $authority,
            'amount' => $amount / 10,
        ];

        $url = 'https://nextpay.org/nx/gateway/verify';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($data));

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['code']) && $result['code'] == 0) {
                return [
                    'success' => true,
                    'ref_id' => $result['Shaparak_Ref_Id'] ?? $authority,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => 'تراکنش ناموفق'
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

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}