<?php

namespace App\Validators;

use Core\Validator;

class BankCardValidator extends Validator
{
    protected array $rules = [
        'card_number' => 'required|numeric|digits:16',
        'card_holder' => 'required|string|min:3|max:100',
        'iban' => 'nullable|string|size:26|starts_with:IR'
    ];

    protected array $messages = [
        'card_number.required' => 'شماره کارت الزامی است',
        'card_number.numeric' => 'شماره کارت باید عدد باشد',
        'card_number.digits' => 'شماره کارت باید ۱۶ رقم باشد',
        'card_holder.required' => 'نام دارنده کارت الزامی است',
        'card_holder.min' => 'نام دارنده کارت نامعتبر است',
        'iban.size' => 'شماره شبا باید ۲۶ کاراکتر باشد',
        'iban.starts_with' => 'شماره شبا باید با IR شروع شود'
    ];
}