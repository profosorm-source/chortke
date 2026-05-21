<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class SocialAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'platform' => 'required|in:instagram,youtube,telegram,tiktok,twitter',
            'username' => 'required|string|min:2|max:255',
            'profile_url' => 'required|string|max:500',
            'follower_count' => 'required|numeric|min:0',
            'following_count' => 'nullable|numeric|min:0',
            'post_count' => 'required|numeric|min:0',
            'account_age_months' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'platform.required' => 'پلتفرم الزامی است',
            'platform.in' => 'پلتفرم انتخاب شده معتبر نیست',
            'username.required' => 'نام کاربری الزامی است',
            'username.min' => 'نام کاربری باید حداقل ۲ کاراکتر باشد',
            'profile_url.required' => 'لینک پروفایل الزامی است',
            'profile_url.max' => 'لینک پروفایل طولانی‌تر از حد مجاز است',
            'follower_count.required' => 'تعداد فالوور الزامی است',
            'follower_count.numeric' => 'تعداد فالوور باید عدد باشد',
            'following_count.numeric' => 'تعداد دنبال‌شده‌ها باید عدد باشد',
            'post_count.required' => 'تعداد پست‌ها الزامی است',
            'post_count.numeric' => 'تعداد پست‌ها باید عدد باشد',
            'account_age_months.required' => 'سن حساب الزامی است',
            'account_age_months.numeric' => 'سن حساب باید عدد باشد',
        ];
    }
}
