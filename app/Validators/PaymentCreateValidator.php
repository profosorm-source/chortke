<?php

namespace App\Validators;

use Core\Validator;

class PaymentCreateValidator extends Validator
{
    protected array $rules = [
        'gateway' => 'required|in:zarinpal,nextpay,idpay,dgpay',
        'amount' => 'required|numeric|min:1000|max:50000000',
        'bank_card_id' => 'required|numeric'
    ];

    protected array $messages = [
        'gateway.required' => 'درگاه الزامی است',
        'amount.required' => 'مبلغ الزامی است',
        'amount.min' => 'حداقل مبلغ ۱۰۰۰ تومان است',
        'bank_card_id.required' => 'انتخاب کارت الزامی است'
    ];
}