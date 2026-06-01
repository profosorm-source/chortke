<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayException;

class PaymentGatewayFactory
{
    private array $gateways;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        array $gateways
    ) {        $this->logger = $logger;

                $this->gateways = $gateways;
    }

    /**
     * ایجاد instance درگاه بر اساس نام
     */
    public function create(string $gateway): PaymentGatewayInterface
    {
        // Input validation
        if (empty($gateway) || strlen($gateway) > 50) {
            $this->logger->error("Invalid payment gateway name: empty or too long");
            throw new PaymentGatewayException("درگاه پرداخت نامعتبر است: نام خالی یا بیش‌ازحد طولانی");
        }

        $gateway = strtolower(trim($gateway));

        if (!isset($this->gateways[$gateway])) {
            $this->logger->error("Invalid payment gateway requested: {$gateway}");
            throw new PaymentGatewayException("درگاه پرداخت نامعتبر است: {$gateway}");
        }

        $gatewayInstance = $this->gateways[$gateway];
        if (!($gatewayInstance instanceof PaymentGatewayInterface)) {
            $this->logger->error("Gateway instance does not implement PaymentGatewayInterface: {$gateway}");
            throw new PaymentGatewayException("Gateway instance must implement PaymentGatewayInterface");
        }

        $this->logger->info("Payment gateway created successfully: {$gateway}");
        return $gatewayInstance;
    }

    /**
     * لیست درگاه‌های فعال
     */
    public static function getAvailableGateways(): array
    {
        return [
            'zarinpal' => [
                'name' => 'زرین‌پال',
                'icon' => 'zarinpal.png',
                'description' => 'پرداخت امن با زرین‌پال'
            ],
            'nextpay' => [
                'name' => 'نکست‌پی',
                'icon' => 'nextpay.png',
                'description' => 'پرداخت سریع با نکست‌پی'
            ],
            'idpay' => [
                'name' => 'آیدی‌پی',
                'icon' => 'idpay.png',
                'description' => 'پرداخت آنلاین آیدی‌پی'
            ],
            'dgpay' => [
                'name' => 'دی‌جی‌پی',
                'icon' => 'dgpay.png',
                'description' => 'درگاه پرداخت دی‌جی‌پی'
            ],
        ];
    }
}