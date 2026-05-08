<?php

namespace App\Data;

/**
 * Common Passwords Database
 * 
 * لیست گسترده‌تر پسوردهای رایج برای جلوگیری از استفاده
 * منبع: OWASP, Have I Been Pwned Top 1000
 */
class CommonPasswords
{
    /**
     * لیست ۱۰۰ پسورد پرتکرار
     * در production باید از یک دیتابیس ۱۰۰۰۰+ تایی استفاده شود
     */
    private static array $passwords = [
        // Top 20 Most Common
        '123456', 'password', '12345678', 'qwerty', '123456789',
        '12345', '1234', '111111', '1234567', 'dragon',
        '123123', 'baseball', 'iloveyou', 'trustno1', '1234567890',
        'sunshine', 'master', 'welcome', 'shadow', 'ashley',
        
        // Common Patterns
        'football', 'jesus', 'monkey', 'ninja', 'mustang',
        'password1', 'password123', 'Password1', 'Password123', 'password!',
        'qwerty123', 'qwertyuiop', 'abc123', 'admin', 'administrator',
        'letmein', 'login', 'passw0rd', 'access', 'secret',
        
        // Persian Common
        '123456789', 'چیزی', 'رمزعبور', 'پسورد', 'admin123',
        
        // Sequential & Keyboard Patterns
        'qazwsx', 'zxcvbnm', 'asdfgh', 'qweasd', '1qaz2wsx',
        '1q2w3e4r', 'qwe123', 'asd123', 'zxc123', 'zaq12wsx',
        
        // Names (Popular)
        'mohammad', 'ali', 'hassan', 'hussein', 'fatima',
        'zahra', 'sara', 'maryam', 'mehdi', 'reza',
        
        // Years & Dates
        '2020', '2021', '2022', '2023', '2024', '2025',
        '1990', '1991', '1992', '1993', '1994', '1995',
        '1980', '1985', '2000', '2010',
        
        // Common Words
        'welcome123', 'admin123', 'user123', 'test123', 'demo123',
        'root', 'toor', 'pass', 'guest', 'user',
        
        // Simple Combinations
        'a1b2c3', '1a2b3c', 'aa123456', 'aaa123', 'aaaa1111',
        '11111111', '00000000', '99999999', '88888888', '12341234',
        
        // Sports & Teams
        'manchester', 'chelsea', 'arsenal', 'liverpool', 'barcelona',
        'realmadrid', 'persepolis', 'esteghlal', 'barcelona',
        
        // Technology
        'windows', 'linux', 'ubuntu', 'android', 'samsung',
        'iphone', 'google', 'facebook', 'instagram', 'twitter',
    ];
    
    /**
     * بررسی اینکه پسورد در لیست رایج هست یا نه
     */
    public static function isCommon(string $password): bool
    {
        $password = strtolower($password);
        
        // بررسی مستقیم
        if (in_array($password, array_map('strtolower', self::$passwords), true)) {
            return true;
        }
        
        // بررسی الگوهای عددی ساده
        if (self::isSimpleNumericPattern($password)) {
            return true;
        }
        
        // بررسی الگوهای کیبوردی
        if (self::isKeyboardPattern($password)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * بررسی الگوهای عددی ساده
     */
    private static function isSimpleNumericPattern(string $password): bool
    {
        // فقط عدد
        if (preg_match('/^\d+$/', $password)) {
            $length = strlen($password);
            
            // تکرار یک رقم
            if (preg_match('/^(\d)\1+$/', $password)) {
                return true;
            }
            
            // الگوی صعودی: 12345678
            if ($length >= 6) {
                $isAscending = true;
                for ($i = 1; $i < $length; $i++) {
                    if ((int)$password[$i] !== ((int)$password[$i-1] + 1) % 10) {
                        $isAscending = false;
                        break;
                    }
                }
                if ($isAscending) return true;
            }
            
            // الگوی نزولی: 87654321
            if ($length >= 6) {
                $isDescending = true;
                for ($i = 1; $i < $length; $i++) {
                    if ((int)$password[$i] !== ((int)$password[$i-1] - 1 + 10) % 10) {
                        $isDescending = false;
                        break;
                    }
                }
                if ($isDescending) return true;
            }
        }
        
        return false;
    }
    
    /**
     * بررسی الگوهای کیبوردی
     */
    private static function isKeyboardPattern(string $password): bool
    {
        $keyboardPatterns = [
            'qwertyuiop', 'asdfghjkl', 'zxcvbnm',
            'qazwsxedc', '1qaz2wsx', 'qweasdzxc',
            'qweasd', 'asdzxc', 'zxcasd',
        ];
        
        $password = strtolower($password);
        
        foreach ($keyboardPatterns as $pattern) {
            if (strpos($password, $pattern) !== false) {
                return true;
            }
            // معکوس الگو
            if (strpos($password, strrev($pattern)) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * دریافت تعداد کل پسوردهای رایج
     */
    public static function count(): int
    {
        return count(self::$passwords);
    }
    
    /**
     * پیشنهاد پسورد امن
     */
    public static function suggest(): string
    {
        $words = ['Sun', 'Moon', 'Star', 'Cloud', 'Rain', 'Storm', 'Thunder', 
                  'Sky', 'Ocean', 'River', 'Mountain', 'Forest', 'Desert'];
        $symbols = ['!', '@', '#', '$', '%', '&', '*'];
        
        $word1 = $words[array_rand($words)];
        $word2 = $words[array_rand($words)];
        $num = random_int(10, 99);
        $symbol = $symbols[array_rand($symbols)];
        
        return $word1 . $num . $word2 . $symbol;
    }
}
