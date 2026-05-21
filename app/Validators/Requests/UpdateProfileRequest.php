<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class UpdateProfileRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => 'nullable|string|min:3|max:255',
            'mobile' => 'nullable|mobile',
            'national_id' => 'nullable|national_code',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'address' => 'nullable|string|max:500',
            'bio' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.string' => 'نام کامل باید متن باشد',
            'full_name.min' => 'نام کامل باید حداقل ۳ کاراکتر باشد',
            'full_name.max' => 'نام کامل بیش از حد طولانی است',
            'mobile.mobile' => 'شماره موبایل نامعتبر است',
            'national_id.national_code' => 'کد ملی نامعتبر است',
            'birth_date.date' => 'تاریخ تولد نامعتبر است',
            'gender.in' => 'جنسیت نامعتبر است',
            'address.max' => 'آدرس بیش از حد طولانی است',
            'bio.max' => 'بیوگرافی بیش از حد طولانی است',
        ];
    }
}
