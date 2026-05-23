<?php

namespace App\Validators;

use Core\Validator;

class ManualDepositValidator extends Validator
{
    protected array $rules = [
        'bank_card_id' => 'required|numeric',
        'amount' => 'required|numeric|min:10000|max:100000000|regex:/^\d+(\.\d{1,4})?$/',
        'tracking_code' => 'required|string|min:5|max:50',
        'deposit_date' => 'required|date',
        'deposit_time' => 'required|string',
        'user_description' => 'nullable|string|max:500'
    ];

    protected array $messages = [
        'bank_card_id.required' => 'انتخاب کارت بانکی الزامی است',
        'amount.required' => 'مبلغ الزامی است',
        'amount.numeric' => 'مبلغ باید عددی باشد',
        'amount.min' => 'حداقل مبلغ ۱۰,۰۰۰ تومان است',
        'amount.max' => 'حداکثر مبلغ ۱۰۰,۰۰۰,۰۰۰ تومان است',
        'amount.regex' => 'فرمت مبلغ نامعتبر است (حداکثر ۴ رقم اعشار مجاز است)',
        'tracking_code.required' => 'شماره پیگیری الزامی است',
        'deposit_date.required' => 'تاریخ الزامی است',
        'deposit_time.required' => 'ساعت الزامی است'
    ];
}