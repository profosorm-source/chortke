<?php

/**
 * XSS Protection Helpers
 * 
 * توابع کمکی برای جلوگیری از حملات XSS
 */

if (!function_exists('xss_clean')) {
    /**
     * پاکسازی کامل از تگ‌های HTML و اسکریپت‌ها
     */
    function xss_clean(string $data): string
    {
        // حذف null bytes
        $data = str_replace(chr(0), '', $data);
        
        // حذف backslashes
        $data = str_replace("\\", "\\\\", $data);
        
        // تبدیل تگ‌های HTML خطرناک
        $data = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $data);
        $data = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $data);
        $data = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $data);
        $data = preg_replace('/<embed\b[^>]*>(.*?)<\/embed>/is', '', $data);
        
        // حذف event handlers
        $data = preg_replace('/on\w+\s*=\s*["\']?[^"\']*["\']?/i', '', $data);
        
        // حذف javascript: در لینک‌ها
        $data = preg_replace('/javascript:/i', '', $data);
        $data = preg_replace('/vbscript:/i', '', $data);
        
        // HTML special chars
        $data = e($data, ENT_QUOTES | ENT_HTML5, 'UTF-8', true);
        
        return $data;
    }
}

if (!function_exists('escape_js')) {
    /**
     * Escape کردن برای استفاده در JavaScript
     */
    function escape_js(string $data): string
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('escape_attr')) {
    /**
     * Escape کردن برای attribute های HTML
     */
    function escape_attr(string $data): string
    {
        return e($data, ENT_QUOTES | ENT_HTML5, 'UTF-8', true);
    }
}

if (!function_exists('escape_url')) {
    /**
     * Escape کردن برای URL
     */
    function escape_url(string $url): string
    {
        // بررسی پروتکل امن
        $allowed_protocols = ['http', 'https', 'mailto', 'tel'];
        
        $parsed = parse_url($url);
        if (isset($parsed['scheme']) && !in_array(strtolower($parsed['scheme']), $allowed_protocols, true)) {
            return '';
        }
        
        return e($url, ENT_QUOTES | ENT_HTML5, 'UTF-8', true);
    }
}

if (!function_exists('safe_html')) {
    /**
     * اجازه دادن به برخی تگ‌های امن HTML
     * برای محتواهایی که نیاز به فرمت‌بندی دارند (مثل پست‌ها، کامنت‌ها)
     */
    function safe_html(string $data): string
    {
        // تگ‌های مجاز
        $allowed_tags = '<p><br><strong><em><u><a><ul><ol><li><blockquote><code><pre>';
        
        // حذف تگ‌های غیرمجاز
        $data = strip_tags($data, $allowed_tags);
        
        // پاکسازی attribute های خطرناک
        $data = preg_replace('/<a[^>]*href\s*=\s*["\']?(javascript:|vbscript:|data:)[^"\']*["\']?[^>]*>/i', '<a>', $data);
        $data = preg_replace('/on\w+\s*=\s*["\']?[^"\']*["\']?/i', '', $data);
        
        // فقط href برای تگ a
        $data = preg_replace('/<a\s+(?!href)[^>]*>/i', '<a>', $data);
        
        return $data;
    }
}

if (!function_exists('sanitize_filename')) {
    /**
     * پاکسازی نام فایل برای جلوگیری از Path Traversal
     */
    function sanitize_filename(string $filename): string
    {
        // حذف کاراکترهای خطرناک
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
        
        // حذف dot files
        $filename = ltrim($filename, '.');
        
        // حذف multiple dots
        $filename = preg_replace('/\.{2,}/', '.', $filename);
        
        // محدود کردن طول
        if (strlen($filename) > 255) {
            $filename = substr($filename, 0, 255);
        }
        
        return $filename;
    }
}

if (!function_exists('is_safe_redirect')) {
    /**
     * بررسی امن بودن URL برای redirect
     */
    function is_safe_redirect(string $url): bool
    {
        // اجازه فقط به URL های نسبی یا همین دامنه
        if (strpos($url, '://') !== false) {
            $parsed = parse_url($url);
            $currentHost = $_SERVER['HTTP_HOST'] ?? '';
            
            if (!isset($parsed['host']) || $parsed['host'] !== $currentHost) {
                return false;
            }
        }
        
        // جلوگیری از javascript: و data:
        if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
            return false;
        }
        
        return true;
    }
}

if (!function_exists('csrf_field')) {
    /**
     * تولید input مخفی برای CSRF token
     */
    function csrf_field(): string
    {
        $token = csrf_token();
        $name = config('csrf.token_name', '_token');
        return '<input type="hidden" name="' . escape_attr($name) . '" value="' . escape_attr($token) . '">';
    }
}

if (!function_exists('csp_nonce')) {
    /**
     * دریافت CSP nonce برای inline scripts/styles
     */
    function csp_nonce(): string
    {
        if (!isset($_SESSION['csp_nonce'])) {
            $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
        }
        return $_SESSION['csp_nonce'];
    }
}

if (!function_exists('validate_email_domain')) {
    /**
     * بررسی معتبر بودن دامنه ایمیل (جلوگیری از disposable emails)
     */
    function validate_email_domain(string $email): bool
    {
        $disposable_domains = [
            'tempmail.com', '10minutemail.com', 'guerrillamail.com',
            'mailinator.com', 'throwaway.email', 'temp-mail.org',
            'getnada.com', 'mohmal.com', 'sharklasers.com',
        ];
        
        $domain = substr(strrchr($email, "@"), 1);
        
        if (in_array(strtolower($domain), $disposable_domains, true)) {
            return false;
        }
        
        // بررسی وجود MX record
        return checkdnsrr($domain, 'MX');
    }
}
