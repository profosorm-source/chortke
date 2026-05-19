<?php

namespace App\Validators;

use Core\Validator;
use Core\Container;
use App\Services\SettingService;

class WithdrawalValidator extends Validator
{
    protected array $rules = [
        'currency' => 'required|in:IRT,USDT',
        'amount' => 'required|numeric',
        'bank_card_id' => 'nullable|numeric',
        'crypto_wallet' => 'nullable|string|min:10|max:120',
        'crypto_network' => 'nullable|in:BNB20,TRC20,ERC20,TON,SOL',
        'user_description' => 'nullable|string|max:500'
    ];

    protected array $messages = [
        'currency.required' => 'ارز الزامی است',
        'amount.required' => 'مبلغ الزامی است',
        'crypto_network.in' => 'شبکه نامعتبر است'
    ];

    public function __construct(array $data = [])
    {
        parent::__construct($data);
        
        // Dynamically fetch and enforce minimum limits from database configuration
        try {
            $container = Container::getInstance();
            if ($container && $container->has(SettingService::class)) {
                $settings = $container->get(SettingService::class);
                $currency = strtoupper((string)($data['currency'] ?? 'IRT'));
                $min = $settings->get(
                    $currency === 'IRT' ? 'min_withdrawal_irt' : 'min_withdrawal_usdt',
                    $currency === 'IRT' ? '10000' : '1'
                );
                $this->rules['amount'] .= '|min:' . $min;
            } else {
                $this->rules['amount'] .= '|min:1';
            }
        } catch (\Throwable $e) {
            $this->rules['amount'] .= '|min:1';
        }
    }

    public function validate(?array $rules = null): void
    {
        parent::validate($rules);

        $amount = $this->data['amount'] ?? null;
        $currency = strtoupper((string)($this->data['currency'] ?? 'IRT'));

        if ($amount !== null && $amount !== '') {
            if ($currency === 'IRT') {
                // IRT must not have any decimal places (must be an integer)
                if (\strpos((string)$amount, '.') !== false) {
                    $parts = \explode('.', (string)$amount, 2);
                    if (isset($parts[1]) && \preg_match('/[1-9]/', $parts[1])) {
                        $this->addError('amount', 'مبلغ تومان نمی‌تواند اعشاری باشد.');
                    }
                }
            } else {
                // USDT can have up to 8 decimal places
                if (\strpos((string)$amount, '.') !== false) {
                    $parts = \explode('.', (string)$amount, 2);
                    if (isset($parts[1]) && \strlen($parts[1]) > 8) {
                        $this->addError('amount', 'مبلغ تتر حداکثر ۸ رقم اعشار می‌تواند داشته باشد.');
                    }
                }
            }
        }
    }
}