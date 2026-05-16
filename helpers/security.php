<?php

/**
 * توابع کمکی امنیتی
 */

if (!function_exists('sanitize')) {
    function sanitize(mixed $input): mixed
    {
        if (is_array($input)) {
            return array_map('sanitize', $input);
        }
        if (is_string($input)) {
            return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
        }
        return $input;
    }
}

if (!function_exists('is_valid_ip')) {
    function is_valid_ip(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('get_real_ip')) {
    function get_real_ip(): string
    {
        return get_client_ip();
    }
}

if (!function_exists('get_user_agent')) {
    function get_user_agent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}

if (!function_exists('secure_hash')) {
    function secure_hash(string $data, string $algo = 'sha256'): string
    {
        $key = config('app.key');
        if (!$key) {
            throw new \RuntimeException('APP_KEY is missing from configuration');
        }
        $allowedAlgos = ['sha256', 'sha384', 'sha512', 'blake2b'];
        if (!in_array($algo, $allowedAlgos, true)) $algo = 'sha256';
        return hash_hmac($algo, $data, (string)$key);
    }
}


if (!function_exists('is_strong_password')) {
    function is_strong_password(string $password): bool
    {
        // حداقل طول
        if (strlen($password) < 8) {
            return false;
        }
        
        // حداکثر طول
        if (strlen($password) > 128) {
            return false;
        }
        
        // حروف بزرگ
        if (!preg_match('/[A-Z]/', $password)) {
            return false;
        }
        
        // حروف کوچک
        if (!preg_match('/[a-z]/', $password)) {
            return false;
        }
        
        // اعداد
        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }
        
        // کاراکترهای خاص
        if (!preg_match('/[\W_]/', $password)) {
            return false;
        }
        
        // بررسی الگوهای رایج و ضعیف
        $commonPasswords = [
            '12345678', 'password', 'qwerty', '123456789',
            'abc123', 'password1', '1234567890', 'pass1234'
        ];
        
        if (in_array(strtolower($password), $commonPasswords, true)) {
            return false;
        }
        
        // بررسی تکرار کاراکترها (مثل aaaa, 1111)
        if (preg_match('/(.)\1{3,}/', $password)) {
            return false;
        }
        
        // بررسی الگوهای Keyboard (qwerty, asdf)
        $keyboardPatterns = ['qwerty', 'asdf', 'zxcv', '1234', 'abcd'];
        foreach ($keyboardPatterns as $pattern) {
            if (stripos($password, $pattern) !== false) {
                return false;
            }
        }
        
        return true;
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trustedProxies = (array)config('app.trusted_proxies', []);

        if (empty($trustedProxies) || !in_array($remoteAddr, $trustedProxies, true)) {
            return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
        }

        $forwardedHeaders = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP', 
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR'
        ];
        
        foreach ($forwardedHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = array_map('trim', explode(',', $_SERVER[$header]));
                
                foreach (array_reverse($ips) as $ip) {
                    // بررسی معتبر بودن IP
                    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                        continue;
                    }
                    
                    // رد کردن IP های Private و Reserved
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        if (!in_array($ip, $trustedProxies, true)) {
                            return $ip;
                        }
                    }
                }
            }
        }
        
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
    }
}

if (!function_exists('generate_device_fingerprint')) {
    function generate_device_fingerprint(): string
    {
        // ✅ امنیت: استفاده از مقادیر سرور‌محور، نه کاربر‌محور
        // HTTP headers می‌تواند توسط کاربر جعل شود
        $components = [
            // مقادیر سرور‌محور (قابل اعتماد)
            get_client_ip(),  // IP کاربر
            session_id() ?? 'unknown',  // Session ID
            (int)($_SERVER['REQUEST_TIME'] ?? time()),  // Server request time
            php_uname(),  // Server info
            gethostname() ?? 'unknown',  // Hostname
        ];
        
        // یک مقدار کاربر‌محور (برای تنوع)، اما با محدودیت
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            // تنها بخش کلیدی user agent (نه سیستم عامل دقیق)
            $ua = $_SERVER['HTTP_USER_AGENT'];
            // استخراج فقط نام مرورگر، نه نسخه دقیق
            if (preg_match('/(Chrome|Firefox|Safari|Edge|Opera)/i', $ua, $m)) {
                $components[] = $m[1];
            }
        }
        
        return hash('sha256', implode('|', $components));
    }
}

if (!function_exists('get_request_id')) {
    function get_request_id(bool $reset = false): string
    {
        static $requestId = null;
        if ($requestId === null || $reset) {
            if (isset($_SERVER['HTTP_X_REQUEST_ID']) && preg_match('/^[a-zA-Z0-9\-_]{8,64}$/', $_SERVER['HTTP_X_REQUEST_ID'])) {
                $requestId = $_SERVER['HTTP_X_REQUEST_ID'];
            } else {
                $requestId = sprintf('REQ_%s_%s', date('YmdHis'), bin2hex(random_bytes(8)));
            }
            $_SERVER['REQUEST_ID'] = $requestId;
        }
        return $requestId;
    }
}

if (!function_exists('sanitize_url')) {
    function sanitize_url(mixed $url): string
    {
        if (empty($url)) return '#';
        $url = trim((string)$url);
        
        $parsed = parse_url($url);
        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : '';
        
        if (!in_array($scheme, ['http', 'https'])) {
            return '#';
        }
        
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('hash_password')) {
    function hash_password(string $password): string
    {
        // CRITICAL: SHA-384 pre-hash to avoid 72-byte truncation in bcrypt and increase entropy
        $preHashed = base64_encode(hash('sha384', $password, true));

        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($preHashed, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]);
        }
        return password_hash($preHashed, PASSWORD_BCRYPT, ['cost' => 14]);
    }
}

if (!function_exists('verify_password')) {
    function verify_password(string $password, string $hash): bool
    {
        $preHashed = base64_encode(hash('sha384', $password, true));
        
        if (password_verify($preHashed, $hash)) {
            return true;
        }

        // Fallback for legacy (non-prehashed) passwords
        return password_verify($password, $hash);
    }
}


if (!function_exists('is_mobile')) {
    function is_mobile(): bool
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return (bool) preg_match('/(android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $userAgent);
    }
}
if (!function_exists('csp_nonce')) {
    /**
     * MEDIUM-03 Fix: Helper to retrieve the CSP nonce for the current request
     */
    function csp_nonce(): string
    {
        try {
            $app = \Core\Application::getInstance();
            if (isset($app->request)) {
                return (string)$app->request->getAttribute(\App\Constants\SessionKeys::CSP_NONCE, '');
            }
        } catch (\Throwable $e) {}
        return '';
    }
}
