<?php
namespace App\Validators;

/**
 * User Validator
 */
class UserValidator
{
    /**
     * Validation برای ثبت‌نام
     */
    public static function validateRegister($data)
    {
        $errors = [];
        
        // Username
        if (empty($data['username'])) {
            $errors['username'][] = 'نام کاربری الزامی است.';
        } elseif (strlen($data['username']) < 3) {
            $errors['username'][] = 'نام کاربری باید حداقل 3 کاراکتر باشد.';
        } elseif (strlen($data['username']) > 50) {
            $errors['username'][] = 'نام کاربری نباید بیشتر از 50 کاراکتر باشد.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
            $errors['username'][] = 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد.';
        }
        
        // Email
        if (empty($data['email'])) {
            $errors['email'][] = 'ایمیل الزامی است.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'فرمت ایمیل نامعتبر است.';
        }
        
        // Password
        if (empty($data['password'])) {
            $errors['password'][] = 'رمز عبور الزامی است.';
        } else {
            $policyErrors = PasswordPolicy::validate($data['password']);
            
            // MEDIUM-M8 Fix: Prevent passwords similar to username or email
            if (PasswordPolicy::isSimilarToUserInfo($data['password'], [
                $data['username'] ?? '',
                $data['email'] ?? '',
                $data['full_name'] ?? ''
            ])) {
                $policyErrors[] = 'رمز عبور نباید شبیه نام کاربری، ایمیل یا نام شما باشد.';
            }

            if (!empty($policyErrors)) {
                $errors['password'] = array_merge($errors['password'] ?? [], $policyErrors);
            }
        }
        
        // Password Confirmation
        if (empty($data['password_confirmation'])) {
            $errors['password_confirmation'][] = 'تکرار رمز عبور الزامی است.';
        } elseif ($data['password'] !== $data['password_confirmation']) {
            $errors['password_confirmation'][] = 'رمز عبور و تکرار آن یکسان نیستند.';
        }
        
        return $errors;
    }

    /**
     * Validation برای Login
     */
    public static function validateLogin($data)
    {
        $errors = [];
        
        if (empty($data['identifier'])) {
            $errors['identifier'][] = 'نام کاربری یا ایمیل الزامی است.';
        }
        
        if (empty($data['password'])) {
            $errors['password'][] = 'رمز عبور الزامی است.';
        }
        
        return $errors;
    }

    /**
     * Validation برای تغییر رمز
     */
    public static function validateChangePassword($data)
    {
        $errors = [];
        
        if (empty($data['current_password'])) {
            $errors['current_password'][] = 'رمز عبور فعلی الزامی است.';
        }
        
        if (empty($data['new_password'])) {
            $errors['new_password'][] = 'رمز عبور جدید الزامی است.';
        } else {
            // MED-11 Fix: Enforce full PasswordPolicy on password change
            $policyErrors = PasswordPolicy::validate($data['new_password']);
            if (!empty($policyErrors)) {
                $errors['new_password'] = array_merge($errors['new_password'] ?? [], $policyErrors);
            }
        }
        
        if (empty($data['new_password_confirmation'])) {
            $errors['new_password_confirmation'][] = 'تکرار رمز عبور جدید الزامی است.';
        } elseif ($data['new_password'] !== $data['new_password_confirmation']) {
            $errors['new_password_confirmation'][] = 'رمز عبور جدید و تکرار آن یکسان نیستند.';
        }
        
        return $errors;
    }
}