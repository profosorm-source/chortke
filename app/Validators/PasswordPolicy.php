<?php
namespace App\Validators;

/**
 * Password Policy
 * 
 * سیاست و اعتبارسنجی رمز عبور
 */
class PasswordPolicy
{
    // تنظیمات پیش‌فرض
    private static $minLength = 8;
    private static $maxLength = 128;
    private static $requireUppercase = true;
    private static $requireLowercase = true;
    private static $requireNumbers = true;
    private static $requireSpecialChars = false;
    private static $preventCommonPasswords = true;

    /**
     * اعتبارسنجی کامل رمز عبور
     */
    public static function validate($password)
    {
        $errors = [];

        // طول
        if (strlen($password) < self::$minLength) {
            $errors[] = "رمز عبور باید حداقل " . self::$minLength . " کاراکتر باشد.";
        }

        if (strlen($password) > self::$maxLength) {
            $errors[] = "رمز عبور نباید بیشتر از " . self::$maxLength . " کاراکتر باشد.";
        }

        // حروف بزرگ
        if (self::$requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "رمز عبور باید حداقل یک حرف بزرگ انگلیسی داشته باشد.";
        }

        // حروف کوچک
        if (self::$requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = "رمز عبور باید حداقل یک حرف کوچک انگلیسی داشته باشد.";
        }

        // اعداد
        if (self::$requireNumbers && !preg_match('/[0-9]/', $password)) {
            $errors[] = "رمز عبور باید حداقل یک عدد داشته باشد.";
        }

        // کاراکترهای خاص
        if (self::$requireSpecialChars && !preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
            $errors[] = "رمز عبور باید حداقل یک کاراکتر خاص داشته باشد.";
        }

        // رمزهای رایج
        if (self::$preventCommonPasswords && self::isCommonPassword($password)) {
            $errors[] = "این رمز عبور بسیار ضعیف و رایج است. لطفاً رمز قوی‌تری انتخاب کنید.";
        }

        return $errors;
    }

    /**
     * بررسی رمزهای رایج
     */
    private static function isCommonPassword($password)
    {
        // استفاده از کلاس CommonPasswords برای بررسی گسترده‌تر
        if (class_exists('\App\Data\CommonPasswords')) {
            return \App\Data\CommonPasswords::isCommon($password);
        }
        
        // Fallback به لیست کوچک اگر کلاس در دسترس نبود
        $commonPasswords = [
            '12345678', 'password', '123456789', '12345', '1234567',
            'password123', 'qwerty', 'abc123', '111111', '123123',
            'admin', 'letmein', 'welcome', 'monkey', '1234567890',
            'Password1', 'password1', '123qwe', 'qwerty123'
        ];

        return in_array(strtolower($password), array_map('strtolower', $commonPasswords));
    }

    /**
     * محاسبه قدرت رمز عبور (0-100)
     */
    public static function strength($password)
    {
        $score = 0;

        // طول
        $length = strlen($password);
        if ($length >= 8) $score += 20;
        if ($length >= 12) $score += 10;
        if ($length >= 16) $score += 10;

        // ترکیب کاراکترها
        if (preg_match('/[a-z]/', $password)) $score += 15;
        if (preg_match('/[A-Z]/', $password)) $score += 15;
        if (preg_match('/[0-9]/', $password)) $score += 15;
        if (preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) $score += 15;

        // تنوع
        $uniqueChars = count(array_unique(str_split($password)));
        if ($uniqueChars > 5) $score += 10;

        return min($score, 100);
    }

    /**
     * دریافت برچسب قدرت
     */
    public static function strengthLabel($password)
    {
        $score = self::strength($password);

        if ($score < 40) return ['label' => 'ضعیف', 'color' => 'danger'];
        if ($score < 60) return ['label' => 'متوسط', 'color' => 'warning'];
        if ($score < 80) return ['label' => 'خوب', 'color' => 'info'];
        return ['label' => 'عالی', 'color' => 'success'];
    }

    /**
     * تنظیم سیاست
     */
    public static function setPolicy(array $policy)
    {
        if (isset($policy['min_length'])) self::$minLength = $policy['min_length'];
        if (isset($policy['max_length'])) self::$maxLength = $policy['max_length'];
        if (isset($policy['require_uppercase'])) self::$requireUppercase = $policy['require_uppercase'];
        if (isset($policy['require_lowercase'])) self::$requireLowercase = $policy['require_lowercase'];
        if (isset($policy['require_numbers'])) self::$requireNumbers = $policy['require_numbers'];
        if (isset($policy['require_special_chars'])) self::$requireSpecialChars = $policy['require_special_chars'];
    }

    /**
     * بررسی شباهت با اطلاعات کاربر
     */
    public static function isSimilarToUserInfo($password, $userInfo = [])
    {
        $password = strtolower($password);

        foreach ($userInfo as $info) {
            $info = strtolower($info);
            
            // اگر رمز شامل نام کاربری، ایمیل یا نام باشد
            if (strlen($info) > 3 && strpos($password, $info) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * تولید رمز تصادفی قوی
     */
    public static function generate($length = 16)
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_+-=[]{}';

        $all = $uppercase . $lowercase . $numbers . $special;

        // حداقل یک کاراکتر از هر نوع
        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        // بقیه کاراکترها
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        // مخلوط کردن
        return str_shuffle($password);
    }
}