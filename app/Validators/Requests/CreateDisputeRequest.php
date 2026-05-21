<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

/**
 * درخواست ایجاد اختلاف (Dispute)
 * فاز ۴ - Section 8.6
 */
class CreateDisputeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'submission_id' => 'required|integer|min:1',
            'reason'        => 'required|in:wrong_proof,not_delivered,low_quality,other',
            'description'   => 'required|string|min:20|max:1000',
            'idempotency_key' => 'required|string|min:10|max:128',
        ];
    }

    public function messages(): array
    {
        return [
            'submission_id.required' => 'شناسه سابمیشن الزامی است',
            'reason.required'        => 'دلیل اختلاف الزامی است',
            'description.required'   => 'توضیحات اختلاف الزامی است',
            'description.min'        => 'توضیحات باید حداقل ۲۰ کاراکتر باشد',
            'idempotency_key.required' => 'کلید یکتای درخواست الزامی است',
        ];
    }
}
