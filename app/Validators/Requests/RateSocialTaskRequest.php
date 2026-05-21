<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

/**
 * درخواست امتیازدهی به تسک اجتماعی
 * فاز ۳ - Section 8.6 (واقعی)
 */
class RateSocialTaskRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'execution_id' => 'required|integer|min:1',
            'rating'       => 'required|integer|between:1,5',
            'review_text'  => 'nullable|string|min:10|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'execution_id.required' => 'شناسه اجرا الزامی است',
            'rating.required'       => 'امتیاز الزامی است',
            'rating.between'        => 'امتیاز باید بین ۱ تا ۵ باشد',
            'review_text.min'       => 'نظر باید حداقل ۱۰ کاراکتر باشد',
        ];
    }
}
