<?php
namespace App\Validators;

/**
 * Password Policy
 * 
 * سیاست و اعتبارسنجی رمز عبور
 * 
 * SECURITY NOTES:
 * - Checks against Have I Been Pwned database using k-anonymity (only first 5 chars of SHA-1 sent)
 * - Enforces minimum complexity requirements
 * - Prevents common passwords and similarity to user info
 */
class PasswordPolicy
{
    // LOW-01 Fix: HIBP k-anonymity API endpoint
    private const HIBP_API_URL = 'https://api.pwnedpasswords.com/range/';
    
    /**
     * اعتبارسنجی کامل رمز عبور
     */
    public static function validate($password, array $userInfo = [])
    {
        $errors = [];
        
        // MED-09 Fix: Use config() instead of mutable static properties for shared-state environments
        $minLength = (int)config('auth.password.min_length', 12);
        $maxLength = (int)config('auth.password.max_length', 128);
        $requireUppercase = (bool)config('auth.password.require_uppercase', true);
        $requireLowercase = (bool)config('auth.password.require_lowercase', true);
        $requireNumbers = (bool)config('auth.password.require_numbers', true);
        $requireSpecialChars = (bool)config('auth.password.require_special_chars', true); // HIGH-09: Default to true
        $preventCommonPasswords = (bool)config('auth.password.prevent_common', true);
        $checkHibp = (bool)config('auth.password.check_hibp', true); // Enabled by default as k-anonymity preserves privacy safely

        // HIGH-05 Fix: Use mb_strlen for characters and check byte length for bcrypt
        $charCount = mb_strlen($password, 'UTF-8');
        $byteCount = strlen($password);

        if ($charCount < $minLength) {
            $errors[] = "رمز عبور باید حداقل " . $minLength . " کاراکتر باشد.";
        }

        if ($charCount > $maxLength) {
            $errors[] = "رمز عبور نباید بیشتر از " . $maxLength . " کاراکتر باشد.";
        }
        
        // MEDIUM-M-04 Fix: Removed 72-byte hard limit. 
        // Passwords should be truncated or pre-hashed (sha384) before bcrypt to support long passwords.

        // حروف بزرگ
        if ($requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "رمز عبور باید حداقل یک حرف بزرگ انگلیسی داشته باشد.";
        }

        // حروف کوچک
        if ($requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = "رمز عبور باید حداقل یک حرف کوچک انگلیسی داشته باشد.";
        }

        // اعداد
        if ($requireNumbers && !preg_match('/[0-9]/', $password)) {
            $errors[] = "رمز عبور باید حداقل یک عدد داشته باشد.";
        }

        // کاراکترهای خاص
        if ($requireSpecialChars && !preg_match('/[!@#$%^&*()_+\\-=\\[\\]{};:\'",.<>?\/\\|`~]/', $password)) {
            $errors[] = "رمز عبور باید حداقل یک کاراکتر خاص داشته باشد.";
        }

        // رمزهای رایج
        if ($preventCommonPasswords && self::isCommonPassword($password)) {
            $errors[] = "این رمز عبور بسیار ضعیف و رایج است. لطفاً رمز قوی‌تری انتخاب کنید.";
        }

        // HIGH-H-08 Fix: Check similarity to user info
        if (!empty($userInfo) && self::isSimilarToUserInfo($password, $userInfo)) {
            $errors[] = 'رمز عبور نباید شبیه اطلاعات شخصی شما (مانند نام کاربری یا ایمیل) باشد.';
        }
        
        // LOW-01 Fix: Check against Have I Been Pwned database using k-anonymity
        // This only sends the first 5 characters of the SHA-1 hash to HIBP, preserving privacy
        if ($checkHibp && !empty($password)) {
            $hibpResult = self::checkHibp($password);
            if ($hibpResult['found']) {
                $errors[] = "این رمز عبور در " . number_format($hibpResult['count']) . " دیتابیس سرقت اطلاعات یافت شده است. لطفاً رمز دیگری انتخاب کنید.";
            }
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
            'Password1', 'password123!', '123qwe', 'qwerty123',
            'chortke', '1234567890', '!@#$%^&*', 'asdfghjkl',
            'football', 'iloveyou', 'sunshine', 'princess', 'charlie'
        ];

        // LOW-03 Fix: Use mb_strtolower for Unicode support
        return in_array(mb_strtolower($password, 'UTF-8'), array_map(fn($p) => mb_strtolower($p, 'UTF-8'), $commonPasswords));
    }

    /**
     * محاسبه قدرت رمز عبور (0-100)
     */
    public static function strength($password)
    {
        $score = 0;

        // طول
        $length = mb_strlen($password, 'UTF-8');
        if ($length >= 8) $score += 20;
        if ($length >= 12) $score += 10;
        if ($length >= 16) $score += 10;

        // ترکیب کاراکترها
        if (preg_match('/[a-z]/', $password)) $score += 15;
        if (preg_match('/[A-Z]/', $password)) $score += 15;
        if (preg_match('/[0-9]/', $password)) $score += 15;
        if (preg_match('/[!@#$%^&*()_+\\-=\\[\\]{};:\'",.<>?\/\\|`~]/', $password)) $score += 15;

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
     * بررسی شباهت با اطلاعات کاربر
     */
    public static function isSimilarToUserInfo($password, $userInfo = [])
    {
        $normalizedPassword = self::normalizeText($password);
        foreach ($userInfo as $info) {
            $normalizedInfo = self::normalizeText((string)$info);
            
            // اگر رمز شامل نام کاربری، ایمیل یا نام باشد
            if (mb_strlen($normalizedInfo, 'UTF-8') > 3 && mb_strpos($normalizedPassword, $normalizedInfo, 0, 'UTF-8') !== false) {
                return true;
            }

            if (function_exists('similar_text')) {
                similar_text($normalizedPassword, $normalizedInfo, $percent);
                if ((int)$percent >= 75) {
                    return true;
                }
            }

            if (function_exists('levenshtein') && levenshtein($normalizedPassword, $normalizedInfo) <= 2) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeText(string $text): string
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($normalized, \Normalizer::FORM_D) ?: $normalized;
        }
        return $normalized;
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

        // LOW-L4 Fix: Use secure Fisher-Yates shuffle with CSPRNG instead of mt_rand-based str_shuffle
        $chars = str_split($password);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        
        return implode('', $chars);
    }

    /**
     * LOW-01 Fix: Check password against Have I Been Pwned database using k-anonymity
     * 
     * This uses the HIBP API with k-anonymity model:
     * 1. Hash the password with SHA-1
     * 2. Send only the first 5 characters to HIBP
     * 3. Check if the remaining hash appears in the response
     * 
     * This preserves user privacy - HIBP never sees the full password hash or the password itself.
     * 
     * @param string $password Plain text password to check
     * @return array ['found' => bool, 'count' => int]
     */
    private static function checkHibp(string $password): array
    {
        try {
            // Hash the password with SHA-1
            $hash = strtoupper(sha1($password));
            $prefix = substr($hash, 0, 5);
            $suffix = substr($hash, 5);
            
            // Query HIBP API with k-anonymity
            $ch = curl_init(self::HIBP_API_URL . $prefix);
            if ($ch === false) {
                return ['found' => false, 'count' => 0];
            }
            
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 3,        // Strict 3-second total timeout
                CURLOPT_CONNECTTIMEOUT => 2, // 2-second connection timeout
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ChortkeApp',
                    'Add-Padding: true'  // HIBP recommends this for privacy
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode !== 200 || $response === false) {
                // Secure fail-open strategy: Do not block users if HIBP API is down or times out
                if (function_exists('logger')) {
                    logger()->warning('password_policy.hibp_api_failed_fail_open', [
                        'http_code' => $httpCode,
                        'curl_error' => $curlError
                    ]);
                }
                return ['found' => false, 'count' => 0];
            }
            
            // Parse response - each line is "SUFFIX:COUNT"
            $lines = explode("\n", $response);
            foreach ($lines as $line) {
                $parts = explode(':', trim($line));
                if (count($parts) >= 2 && $parts[0] === $suffix) {
                    $count = (int)$parts[1];
                    // Only flag as breached if count is significant (> 100 occurrences)
                    // This avoids false positives for very common password patterns
                    if ($count > 100) {
                        return ['found' => true, 'count' => $count];
                    }
                }
            }
            
            return ['found' => false, 'count' => 0];
            
        } catch (\Throwable $e) {
            // Don't fail validation if HIBP check fails
            if (function_exists('logger')) {
                logger()->warning('password_policy.hibp_check_failed', [
                    'error' => $e->getMessage()
                ]);
            }
            return ['found' => false, 'count' => 0];
        }
    }
}