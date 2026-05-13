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
        } elseif (strlen($data['password']) < 8) {
            $errors['password'][] = 'رمز عبور باید حداقل 8 کاراکتر باشد.';
        } elseif (!preg_match('/[A-Z]/', $data['password'])) {
            $errors['password'][] = 'رمز عبور باید حداقل یک حرف بزرگ داشته باشد.';
        } elseif (!preg_match('/[a-z]/', $data['password'])) {
            $errors['password'][] = 'رمز عبور باید حداقل یک حرف کوچک داشته باشد.';
        } elseif (!preg_match('/[0-9]/', $data['password'])) {
            $errors['password'][] = 'رمز عبور باید حداقل یک عدد داشته باشد.';
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
        } elseif (strlen($data['new_password']) < 8) {
            $errors['new_password'][] = 'رمز عبور جدید باید حداقل 8 کاراکتر باشد.';
        }
        
        if (empty($data['new_password_confirmation'])) {
            $errors['new_password_confirmation'][] = 'تکرار رمز عبور جدید الزامی است.';
        } elseif ($data['new_password'] !== $data['new_password_confirmation']) {
            $errors['new_password_confirmation'][] = 'رمز عبور جدید و تکرار آن یکسان نیستند.';
        }
        
        return $errors;
    }
}