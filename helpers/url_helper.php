<?php

/**
 * توابع کمکی URL و روتینگ
 */

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        // جلوگیری از آدرس‌های مطلق خارجی ناامن برای رفع خطر Open Redirect
        $trimmedPath = ltrim($path, '/\\');
        if (preg_match('#^https?://#i', $trimmedPath) || str_starts_with($trimmedPath, '//')) {
            throw new \InvalidArgumentException('url() accepts relative paths only');
        }

        // ۱. اولویت با APP_URL تنظیم شده در config است
        $baseUrl = config('app.url') ?: setting('site_url');

        if (!$baseUrl) {
            // ۳. در نهایت اگر هیچ‌کدام نبود، از SERVER تشخیص بده (Sanitized)
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            // پاکسازی Host برای جلوگیری از تزریق
            $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $host);
            
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = str_replace('/public/index.php', '', $scriptName);
            $basePath = str_replace('\\', '/', $basePath);
            
            $baseUrl = $protocol . '://' . $host . $basePath;
        }
        
        $path = '/' . ltrim($path, '/');
        return rtrim($baseUrl, '/') . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $baseUrl = config('app.url') ?: url('/');
        return rtrim($baseUrl, '/') . '/' . $path;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): never
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            $appUrl  = config('app.url', '');
            $appHost = parse_url($appUrl, PHP_URL_HOST) ?? '';
            $pathHost = parse_url($path, PHP_URL_HOST) ?? '';
            $isSameHost = ($pathHost === $appHost) || ($appHost && str_ends_with($pathHost, '.' . $appHost));

            if (!$isSameHost && $appHost) {
                header('Location: ' . rtrim($appUrl, '/') . '/');
                exit;
            }

            header("Location: {$path}");
            exit;
        }
        
        header("Location: " . url($path));
        exit;
    }
}

if (!function_exists('back')) {
    function back(): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $appHost = parse_url(config('app.url', ''), PHP_URL_HOST);
        $refHost = parse_url($referer, PHP_URL_HOST);
        
        if ($refHost === $appHost) {
            redirect($referer);
        }
        redirect(url('/'));
    }
}
