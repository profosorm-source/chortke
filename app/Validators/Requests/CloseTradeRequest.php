<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CloseTradeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'close_price'        => 'required|numeric|min:0.000001',
            'profit_loss_amount' => 'required|numeric',
            'close_time'         => 'nullable|date_format:Y-m-d H:i:s',
            'status'             => 'nullable|string',
            'notes'              => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'close_price.required'        => 'قیمت بسته شدن الزامی است.',
            'close_price.numeric'         => 'قیمت بسته شدن نامعتبر است.',
            'close_price.min'             => 'قیمت بسته شدن نامعتبر است.',
            'profit_loss_amount.required' => 'مقدار سود/زیان الزامی است.',
            'profit_loss_amount.numeric'  => 'مقدار سود/زیان نامعتبر است.',
        ];
    }
}
