<?php

namespace App\Validators;

use Core\Validator;

class CryptoIntentValidator extends Validator
{
    protected array $rules = [
        'network' => 'required|in:BNB20,TRC20,ERC20,TON,SOL',
        'requested_amount' => 'required|numeric|min:1|max:1000000'
    ];

    protected array $messages = [
        'network.required' => 'انتخاب شبکه الزامی است',
        'network.in' => 'شبکه نامعتبر است',
        'requested_amount.required' => 'مبلغ الزامی است',
        'requested_amount.min' => 'حداقل مبلغ ۱ USDT است'
    ];
}