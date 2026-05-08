<?php

namespace App\Validators;

use Core\Validator;

class WithdrawalValidator extends Validator
{
    protected array $rules = [
        'currency' => 'required|in:IRT,USDT',
        'amount' => 'required|numeric|min:1',
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
}