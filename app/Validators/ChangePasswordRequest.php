<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * ChangePasswordRequest
 *
 * اعتبارسنجی فرم تغییر رمز عبور.
 */
class ChangePasswordRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'current_password'          => 'required|string',
            'new_password'              => 'required|string|min:8',
            'new_password_confirmation' => 'required|string',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        // MED-11 Fix: Enforce full PasswordPolicy on password change
        $policyErrors = PasswordPolicy::validate((string)($this->data['new_password'] ?? ''));
        if (!empty($policyErrors)) {
            $this->errors['new_password'] = array_merge($this->errors['new_password'] ?? [], $policyErrors);
            return false;
        }

        if (($this->data['new_password'] ?? '') !== ($this->data['new_password_confirmation'] ?? '')) {
            $this->errors['new_password_confirmation'][] = 'رمز عبور جدید و تکرار آن یکسان نیستند.';
            return false;
        }

        return true;
    }

    public function messages(): array
    {
        return [
            'current_password.required'          => 'رمز عبور فعلی الزامی است',
            'new_password.required'               => 'رمز عبور جدید الزامی است',
            'new_password.min'                    => 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد',
            'new_password_confirmation.required'  => 'تکرار رمز عبور جدید الزامی است',
        ];
    }
}
