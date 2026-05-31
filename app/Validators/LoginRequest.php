<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * LoginRequest
 *
 * اعتبارسنجی فرم ورود به سیستم.
 */
class LoginRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'password' => 'required|string',
        ];
    }

    public function validate(): bool
    {
        // Normalize before running rules
        if (!empty($this->data['email'])) {
            $this->data['email'] = mb_strtolower(trim((string)$this->data['email']), 'UTF-8');
        }
        if (!empty($this->data['identifier'])) {
            $this->data['identifier'] = mb_strtolower(trim((string)$this->data['identifier']), 'UTF-8');
        }

        // At least email or identifier must exist
        if (empty($this->data['email']) && empty($this->data['identifier'])) {
            $this->errors['login'][] = 'نام کاربری یا ایمیل الزامی است.';
            return false;
        }

        // MEDIUM-05 Fix: Prevent DoS from extremely large inputs
        if (!empty($this->data['email']) && strlen((string)$this->data['email']) > 255) {
            $this->errors['login'][] = 'ایمیل نامعتبر است (بیش از حد طولانی).';
            return false;
        }
        if (!empty($this->data['identifier']) && strlen((string)$this->data['identifier']) > 255) {
            $this->errors['login'][] = 'شناسه کاربری نامعتبر است (بیش از حد طولانی).';
            return false;
        }

        if (empty($this->data['password'])) {
            $this->errors['password'][] = 'رمز عبور الزامی است.';
            return false;
        }
        // Prevent bcrypt CPU exhaustion
        if (strlen((string)$this->data['password']) > 255) {
            $this->errors['password'][] = 'رمز عبور نامعتبر است (بیش از حد طولانی).';
            return false;
        }

        $this->validated = $this->data;
        return true;
    }

    public function messages(): array
    {
        return [];
    }
}
