<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;
use App\Services\SettingService;
use Core\Container;

/**
 * درخواست ایجاد تیکت پشتیبانی
 * فاز ۴ - Section 8.6 Validation Layer
 */
class CreateTicketRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'subject'     => 'required|string|min:5|max:200',
            'category'    => 'required|in:technical,financial,account,abuse,other',
            'priority'    => 'required|in:low,normal,high,urgent',
            'message'     => 'required|string|min:20|max:5000',
            'related_id'  => 'nullable|integer',
            'idempotency_key' => 'required|string|min:10|max:128',
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => 'عنوان تیکت الزامی است',
            'subject.min'      => 'عنوان باید حداقل ۵ کاراکتر باشد',
            'subject.max'      => 'عنوان نمی‌تواند بیشتر از ۲۰۰ کاراکتر باشد',
            'category.required'=> 'دسته‌بندی تیکت الزامی است',
            'priority.required'=> 'اولویت تیکت الزامی است',
            'message.required' => 'متن پیام الزامی است',
            'message.min'      => 'متن پیام باید حداقل ۲۰ کاراکتر باشد',
            'idempotency_key.required' => 'کلید یکتای درخواست الزامی است',
        ];
    }
}
