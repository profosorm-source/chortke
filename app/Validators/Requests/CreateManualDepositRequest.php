<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateManualDepositRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount'          => 'required|numeric|min:10000|max:100000000|regex:/^\d+(\.\d{1,4})?$/',
            'tracking_code'   => 'required|string|min:5|max:50',
            'deposit_date'    => 'required|date',
            'deposit_time'    => 'required|string|regex:/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/',
            'user_description'=> 'nullable|string|max:500',
            'card_id'         => 'nullable|integer|min:1',
            'bank_card_id'    => 'nullable|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required'        => 'مبلغ الزامی است',
            'amount.numeric'         => 'مبلغ باید عددی باشد',
            'amount.min'             => 'حداقل مبلغ ۱۰,۰۰۰ تومان است',
            'amount.max'             => 'حداکثر مبلغ ۱۰۰,۰۰۰,۰۰۰ تومان است',
            'amount.regex'           => 'فرمت مبلغ نامعتبر است',
            'tracking_code.required' => 'شماره پیگیری الزامی است',
            'tracking_code.min'      => 'شماره پیگیری باید حداقل ۵ کاراکتر باشد',
            'deposit_date.required'  => 'تاریخ واریز الزامی است',
            'deposit_date.date'      => 'تاریخ واریز نامعتبر است',
            'deposit_time.required'  => 'ساعت واریز الزامی است',
            'deposit_time.regex'     => 'فرمت ساعت واریز باید HH:MM باشد',
            'user_description.max'   => 'توضیحات نمی‌تواند بیش از ۵۰۰ کاراکتر باشد',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $cardId = (int)($this->data['card_id'] ?? $this->data['bank_card_id'] ?? 0);
        if ($cardId < 1) {
            $this->errors['card_id'][] = 'انتخاب کارت بانکی الزامی است';
            return false;
        }

        return true;
    }
}
