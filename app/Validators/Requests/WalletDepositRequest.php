<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

/**
 * فرم درخواست برای واریز به کیف پول
 *
 * استفاده در WalletController::deposit()
 */
class WalletDepositRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1000|max:10000000', // حداقل ۱۰۰۰ تومان، حداکثر ۱۰ میلیون
            'gateway' => 'required|in:idpay,nextpay,zarinpal,dgpay',
            'idempotency_key' => 'required|string|min:10|max:128',
            'callback_url' => 'nullable|url',
            'description' => 'nullable|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'مبلغ واریز الزامی است',
            'amount.numeric' => 'مبلغ باید عدد باشد',
            'amount.min' => 'حداقل مبلغ واریز ۱۰۰۰ تومان است',
            'amount.max' => 'حداکثر مبلغ واریز ۱۰ میلیون تومان است',
            'gateway.required' => 'درگاه پرداخت الزامی است',
            'gateway.in' => 'درگاه پرداخت انتخاب شده معتبر نیست',
            'idempotency_key.required' => 'ارائه کلید یکتای درخواست (Idempotency Key) الزامی است',
            'idempotency_key.string' => 'فرمت کلید درخواست نامعتبر است',
            'idempotency_key.min' => 'طول کلید درخواست حداقل ۱۰ کاراکتر است',
            'callback_url.url' => 'آدرس بازگشت باید یک URL معتبر باشد',
            'description.max' => 'توضیحات نمی‌تواند بیش از ۲۵۵ کاراکتر باشد',
        ];
    }
}