<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;
use App\Services\Settings\AppSettings;
use Core\Container;

/**
 * درخواست ایجاد تسک اجتماعی
 * فاز ۳ - Section 8.6 (واقعی)
 */
class CreateSocialTaskRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'platform'        => 'required|in:youtube,instagram,twitter,telegram,aparat',
            'task_type'       => 'required|in:like,comment,subscribe,view,share,follow',
            'target_url'      => 'required|url',
            'reward_amount'   => 'required|numeric|min:100',
            'total_quantity'  => 'required|integer|min:1|max:5000',
            'deadline_hours'  => 'required|integer|min:1|max:72',
            'proof_type'      => 'required|in:screenshot,link,video',
            'idempotency_key' => 'required|string|min:10|max:128',
        ];
    }

    public function messages(): array
    {
        return [
            'platform.required'      => 'پلتفرم الزامی است',
            'task_type.required'     => 'نوع تسک الزامی است',
            'target_url.required'    => 'لینک هدف الزامی است',
            'target_url.url'         => 'لینک وارد شده معتبر نیست',
            'reward_amount.required' => 'میزان پاداش الزامی است',
            'total_quantity.required'=> 'تعداد الزامی است',
            'deadline_hours.required'=> 'مهلت الزامی است',
            'proof_type.required'    => 'نوع مدرک الزامی است',
            'idempotency_key.required' => 'کلید یکتا الزامی است',
        ];
    }
}
