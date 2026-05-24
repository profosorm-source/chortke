<?php

/**
 * توابع کمکی امنیتی
 */

if (!function_exists('escape_html')) {
    function escape_html(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('escape_js')) {
    function escape_js(string $str): string
    {
        return json_encode($str, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('escape_url')) {
    function escape_url(string $str): string
    {
        return rawurlencode($str);
    }
}

if (!function_exists('escape_css')) {
    function escape_css(string $str): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_]/', '', $str);
    }
}

if (!function_exists('sanitize')) {
    function sanitize(mixed $input): mixed
    {
        if (is_array($input)) {
            return array_map('sanitize', $input);
        }
        if (is_string($input)) {
            if (function_exists('config') && config('app.env') !== 'production') {
                @trigger_error('sanitize() is deprecated. Use context-specific escape_*() functions instead.', E_USER_DEPRECATED);
            }
            return escape_html(strip_tags(trim($input)));
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

if (!function_exists('secure_key')) {
    function secure_key(): string
    {
        $key = config('app.key');
        if (empty($key) || strlen($key) < 32 || $key === 'default_key') {
            throw new \RuntimeException('Secure application key (APP_KEY) is not properly configured.');
        }
        return (string)$key;
    }
}

if (!function_exists('secure_hash')) {
    function secure_hash(string $data, string $algo = 'sha256'): string
    {
        $key = secure_key();
        $allowedAlgos = ['sha256', 'sha384', 'sha512', 'blake2b'];
        if (!in_array($algo, $allowedAlgos, true)) $algo = 'sha256';
        return hash_hmac($algo, $data, $key);
    }
}

if (!function_exists('get_trace_context')) {
    function get_trace_context(): array
    {
        $traceId = $_SERVER['TRACE_ID'] ?? $_SERVER['HTTP_X_B3_TRACEID'] ?? $_SERVER['HTTP_X_CORRELATION_ID'] ?? bin2hex(random_bytes(16));
        $spanId = $_SERVER['SPAN_ID'] ?? $_SERVER['HTTP_X_B3_SPANID'] ?? bin2hex(random_bytes(8));
        $parentId = $_SERVER['PARENT_SPAN_ID'] ?? $_SERVER['HTTP_X_B3_PARENTSPANID'] ?? null;

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentId,
        ];
    }
}

if (!function_exists('trace_headers')) {
    function trace_headers(): array
    {
        $traceContext = get_trace_context();
        return [
            'X-B3-TraceId: ' . $traceContext['trace_id'],
            'X-B3-SpanId: ' . $traceContext['span_id'],
            'X-B3-ParentSpanId: ' . ($traceContext['parent_span_id'] ?? ''),
            'X-B3-Sampled: 1',
            'X-Request-Id: ' . ($_SERVER['REQUEST_ID'] ?? $traceContext['trace_id']),
        ];
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

if (!function_exists('ip_in_range')) {
    function ip_in_range(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        
        list($subnet, $bits) = explode('/', $range);
        $bits = (int)$bits;
        
        // Check if it's IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            
            $ipHex = bin2hex($ipBin);
            $subnetHex = bin2hex($subnetBin);
            
            $ipBits = '';
            $subnetBits = '';
            
            for ($i = 0; $i < 32; $i++) {
                $ipBits .= str_pad(base_convert($ipHex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
                $subnetBits .= str_pad(base_convert($subnetHex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
            }
            
            return substr($ipBits, 0, $bits) === substr($subnetBits, 0, $bits);
        }
        
        // Check if it's IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            
            $mask = (int)(~((1 << (32 - $bits)) - 1));
            if ($bits === 0) {
                $mask = 0;
            }
            
            return ($ipLong & $mask) === ($subnetLong & $mask);
        }
        
        return false;
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trustedProxies = (array)config('app.trusted_proxies', []);
        if (empty($trustedProxies)) {
            $trustedProxies = ['127.0.0.1', '::1'];
        }

        $isTrusted = false;
        foreach ($trustedProxies as $proxy) {
            if (ip_in_range($remoteAddr, $proxy)) {
                $isTrusted = true;
                break;
            }
        }

        if (!$isTrusted) {
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
                    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                        continue;
                    }
                    
                    $ipTrusted = false;
                    foreach ($trustedProxies as $proxy) {
                        if (ip_in_range($ip, $proxy)) {
                            $ipTrusted = true;
                            break;
                        }
                    }
                    if (!$ipTrusted) {
                        return $ip;
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
        $ip = get_client_ip();
        $sessionId = session_id() ?: 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown';

        // Extract stable browser family
        $browserFamily = 'unknown';
        if (preg_match('/(Chrome|Firefox|Safari|Edge|Opera)/i', $userAgent, $m)) {
            $browserFamily = $m[1];
        }

        // Mask IP for mobile subnet stability (/24 for IPv4, /48 for IPv6)
        $ipMasked = $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $ipMasked = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            $ipMasked = implode(':', array_slice($parts, 0, 3)) . ':0:0:0:0:0';
        }

        $components = [
            $ipMasked,
            $sessionId,
            $browserFamily,
            substr($acceptLang, 0, 5),
            secure_key(), // Application key salt
        ];

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

if (!function_exists('get_trace_context')) {
    function get_trace_context(): array
    {
        $traceId = $_SERVER['HTTP_X_TRACE_ID'] ?? ($_SERVER['REQUEST_ID'] ?? get_request_id());
        $spanId = $_SERVER['HTTP_X_SPAN_ID'] ?? bin2hex(random_bytes(8));
        $parentSpanId = $_SERVER['HTTP_X_PARENT_SPAN_ID'] ?? null;

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
        ];
    }
}

if (!function_exists('trace_headers')) {
    function trace_headers(array $headers = []): array
    {
        $context = get_trace_context();
        $default = [
            'X-Request-Id: ' . ($_SERVER['REQUEST_ID'] ?? get_request_id()),
            'X-Trace-Id: ' . $context['trace_id'],
            'X-Span-Id: ' . $context['span_id'],
        ];
        if (!empty($context['parent_span_id'])) {
            $default[] = 'X-Parent-Span-Id: ' . $context['parent_span_id'];
        }

        return array_merge($default, $headers);
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
