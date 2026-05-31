<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;
use App\Models\TradingRecord;

class CreateTradeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $buy = TradingRecord::DIRECTION_BUY;
        $sell = TradingRecord::DIRECTION_SELL;

        return [
            'direction'   => "required|in:{$buy},{$sell}",
            'open_price'  => 'required|numeric|min:0.000001',
            'pair'        => 'required|string|min:2',
            'lot_size'    => 'nullable|numeric|min:0',
            'close_price' => 'nullable|numeric|min:0',
            'stop_loss'   => 'nullable|numeric|min:0',
            'take_profit' => 'nullable|numeric|min:0',
            'notes'       => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'direction.required' => 'جهت ترید نامعتبر است.',
            'direction.in'       => 'جهت ترید نامعتبر است.',
            'open_price.required'=> 'قیمت باز شدن باید بیشتر از صفر باشد.',
            'open_price.numeric' => 'قیمت باز شدن باید بیشتر از صفر باشد.',
            'open_price.min'     => 'قیمت باز شدن باید بیشتر از صفر باشد.',
            'pair.required'      => 'جفت ارز الزامی است.',
            'pair.string'        => 'جفت ارز الزامی است.',
        ];
    }
}
