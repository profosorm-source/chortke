<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;

class PaymentGatewayFactory
{
    private array $gateways;

    public function __construct(array $gateways)
    {
        $this->gateways = $gateways;
    }

    /**
     * ایجاد instance درگاه بر اساس نام
     */
    public function create(string $gateway): PaymentGatewayInterface
    {
        $gateway = strtolower(trim($gateway));

        if (!isset($this->gateways[$gateway])) {
            throw new \Exception('درگاه پرداخت نامعتبر است');
        }

        $gatewayInstance = $this->gateways[$gateway];
        if (!($gatewayInstance instanceof PaymentGatewayInterface)) {
            throw new \Exception('Gateway instance must implement PaymentGatewayInterface');
        }

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