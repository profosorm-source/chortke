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
        $baseUrl = config('app.url');

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

        $baseUrl = rtrim($baseUrl, '/');
        $path = '/' . ltrim($path, '/');

        $parsedBasePath = parse_url($baseUrl, PHP_URL_PATH) ?: '';
        $parsedBasePath = rtrim($parsedBasePath, '/');
        if ($parsedBasePath !== '' && ($path === $parsedBasePath || str_starts_with($path, $parsedBasePath . '/'))) {
            $path = substr($path, strlen($parsedBasePath));
            $path = '/' . ltrim($path, '/');
        }

        return $baseUrl . $path;
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
    function redirect(string $path, int $statusCode = 302): never
    {
        app(\Core\Response::class)->redirect($path, $statusCode);
        exit;
    }
}

if (!function_exists('back')) {
    function back(): never
    {
        app(\Core\Response::class)->back();
        exit;
    }
}
