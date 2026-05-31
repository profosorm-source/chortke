<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * RegisterRequest
 *
 * اعتبارسنجی فرم ثبت‌نام کاربر جدید.
 */
class RegisterRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $minLength = (int)config('auth.password.min_length', 12);
        return [
            'full_name' => 'required|string|min:3|max:255',
            'email'     => 'required|email',
            'password'  => "required|min:{$minLength}",
            'username'  => 'nullable|string|min:3|max:64',
        ];
    }

    public function validate(): bool
    {
        // Normalize email
        if (!empty($this->data['email'])) {
            $this->data['email'] = mb_strtolower(trim((string)$this->data['email']), 'UTF-8');
        }

        if (!parent::validate()) {
            return false;
        }

        // Password similarity check (MEDIUM-M8 Fix)
        $password = (string)($this->data['password'] ?? '');
        $policyErrors = PasswordPolicy::validate($password, [
            'username'  => $this->data['username'] ?? '',
            'email'     => $this->data['email'] ?? '',
            'full_name' => $this->data['full_name'] ?? '',
        ]);
        if (!empty($policyErrors)) {
            $this->errors['password'] = array_merge($this->errors['password'] ?? [], $policyErrors);
            return false;
        }

        return true;
    }

    public function messages(): array
    {
        $minLength = (int)config('auth.password.min_length', 12);
        return [
            'full_name.required' => 'نام و نام خانوادگی الزامی است',
            'full_name.min'      => 'نام و نام خانوادگی باید حداقل ۳ کاراکتر باشد',
            'full_name.max'      => 'نام و نام خانوادگی بیش از حد طولانی است',
            'email.required'     => 'ایمیل الزامی است',
            'email.email'        => 'ایمیل وارد شده معتبر نیست',
            'password.required'  => 'رمز عبور الزامی است',
            'password.min'       => "رمز عبور باید حداقل {$minLength} کاراکتر باشد",
            'username.min'       => 'نام کاربری باید حداقل ۳ کاراکتر باشد',
            'username.max'       => 'نام کاربری بیش از حد طولانی است',
        ];
    }
}
