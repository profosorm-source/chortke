<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;
use App\Services\Settings\AppSettings;
use Core\Container;
use App\Exceptions\BusinessException;

/**
 * فرم درخواست برداشت وجه
 *
 * لایه Input Validation + اعتبارسنجی اولیه بیزینسی
 * استفاده در WithdrawalController::store()
 */
class CreateWithdrawalRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $rules = [
            'amount'           => 'required|numeric|min:1000',
            'currency'         => 'required|in:IRT,USDT',
            'idempotency_key'  => 'required|string|min:10|max:128',
            'user_description' => 'nullable|string|max:500',
        ];

        $currency = strtoupper($this->data['currency'] ?? 'IRT');

        if ($currency === 'IRT') {
            $rules['bank_card_id'] = 'required|integer|min:1';
        } else {
            $rules['crypto_wallet']   = 'required|string|min:10|max:120';
            $rules['crypto_network']  = 'required|in:BNB20,TRC20,ERC20,TON,SOL';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'amount.required'           => 'مبلغ برداشت الزامی است',
            'amount.numeric'            => 'مبلغ باید عدد باشد',
            'amount.min'                => 'مبلغ وارد شده کمتر از حداقل مجاز است',
            'currency.required'         => 'انتخاب ارز الزامی است',
            'currency.in'               => 'ارز انتخاب شده معتبر نیست',
            'bank_card_id.required'     => 'انتخاب کارت بانکی الزامی است',
            'crypto_wallet.required'    => 'آدرس والت کریپتو الزامی است',
            'crypto_network.required'   => 'انتخاب شبکه الزامی است',
            'idempotency_key.required'  => 'کلید یکتای درخواست الزامی است',
            'user_description.max'      => 'توضیحات نمی‌تواند بیش از ۵۰۰ کاراکتر باشد',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $validated = $this->validated();
        $currency = strtoupper($validated['currency'] ?? 'IRT');
        $amount = (float)($validated['amount'] ?? 0);

        try {
            $settings = Container::getInstance()->make(AppSettings::class);
            $minKey = $currency === 'IRT' ? 'min_withdrawal_irt' : 'min_withdrawal_usdt';
            $minAmount = (float)$settings->get($minKey, $currency === 'IRT' ? 50000 : 10);

            if ($amount < $minAmount) {
                $this->errors['amount'][] = "حداقل مبلغ برداشت برای {$currency} برابر {$minAmount} است";
                return false;
            }

            // چک اعشاری برای تومان
            if ($currency === 'IRT' && fmod($amount, 1) !== 0.0) {
                $this->errors['amount'][] = 'مبلغ تومان نمی‌تواند اعشاری باشد';
                return false;
            }
        } catch (\Throwable $e) {
            if ($amount < 10000) {
                $this->errors['amount'][] = 'حداقل مبلغ برداشت ۱۰۰۰۰ تومان است';
                return false;
            }
        }

        return true;
    }
}
