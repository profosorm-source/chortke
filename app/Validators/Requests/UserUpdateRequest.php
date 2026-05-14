<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class UserUpdateRequest extends BaseFormRequest
{
    /**
     * بررسی سطح دسترسی انجام عملیات
     */
    public function authorize(): bool
    {
        // Authorization checks are typically handled by BaseAdminController's access policies.
        return true;
    }

    /**
     * قوانین اعتبارسنجی
     */
    public function rules(): array
    {
        $rules = [
            'full_name' => 'required|min:3|max:100',
            'email'     => 'required|email',
            'role'      => 'required|in:user,admin,support',
            'status'    => 'required|in:active,inactive,suspended,banned'
        ];

        // بررسی شرطی رمز عبور: تنها در صورتی که کاربر آن را پر کرده باشد اعتبارسنجی شود
        if (!empty($this->data['password'])) {
            $rules['password'] = 'min:8';
        }

        return $rules;
    }

    /**
     * پیام‌های اختصاصی اعتبارسنجی
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'وارد کردن نام کامل الزامی است.',
            'email.required'     => 'وارد کردن ایمیل الزامی است.',
            'email.email'        => 'فرمت ایمیل وارد شده صحیح نیست.',
            'password.min'       => 'کلمه عبور باید حداقل ۸ کاراکتر باشد.',
        ];
    }
}
