<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class LotteryRoundRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:255',
            'type' => 'required|in:weekly,monthly',
            'entry_fee' => 'required|numeric|min:0',
            'prize_amount' => 'required|numeric|min:0',
            'duration_days' => 'required|numeric|min:1|max:31',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان قرعه‌کشی الزامی است',
            'title.min' => 'عنوان باید حداقل ۳ کاراکتر باشد',
            'type.required' => 'نوع قرعه‌کشی الزامی است',
            'type.in' => 'نوع قرعه‌کشی نامعتبر است',
            'entry_fee.required' => 'هزینه ورودی الزامی است',
            'entry_fee.numeric' => 'هزینه ورودی باید عدد باشد',
            'prize_amount.required' => 'مبلغ جایزه الزامی است',
            'prize_amount.numeric' => 'مبلغ جایزه باید عدد باشد',
            'duration_days.required' => 'مدت زمان قرعه‌کشی الزامی است',
            'duration_days.numeric' => 'مدت زمان باید عدد باشد',
            'start_date.required' => 'تاریخ شروع الزامی است',
            'end_date.required' => 'تاریخ پایان الزامی است',
        ];
    }
}
