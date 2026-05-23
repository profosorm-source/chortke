<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class StoreInfluencerProfileRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'platform' => 'required|in:instagram,telegram',
            'username' => 'required|string|min:3|max:50',
            'bio' => 'required|string|min:10|max:1000',
            'category' => 'required|string',
            'follower_count' => 'required|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'platform.required' => 'انتخاب پلتفرم الزامی است.',
            'platform.in' => 'پلتفرم انتخابی نامعتبر است.',
            'username.required' => 'نام کاربری الزامی است.',
            'username.min' => 'نام کاربری باید حداقل ۳ کاراکتر باشد.',
            'bio.required' => 'بیوگرافی الزامی است.',
            'bio.min' => 'بیوگرافی باید حداقل ۱۰ کاراکتر باشد.',
            'category.required' => 'دسته‌بندی الزامی است.',
            'follower_count.required' => 'تعداد فالوور/عضو الزامی است.',
            'follower_count.integer' => 'تعداد فالوور باید عدد صحیح باشد.',
        ];
    }
}
