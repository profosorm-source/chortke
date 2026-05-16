<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Closure;

/**
 * CorsMiddleware — مدیریت متمرکز درخواست‌های متقاطع (CORS) و احراز هویت Preflight
 */
class CorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // اگر درخواست OPTIONS (پیش‌پرواز) بود، نیازی به رفتن به مرحله بعد نداریم
        $isOptions = ($request->method() === 'OPTIONS');

        if ($isOptions) {
            // ایجاد مستقیم پاسخ خالی ۲۴۴
            $response = new Response();
            $response->status(204);
        } else {
            // برای درخواست‌های معمولی اجازه اجرا بدهیم تا محتوا را بگیریم
            $response = $next($request);
            if (!$response instanceof Response) {
                $content = (string)$response;
                $response = new Response();
                $response->setContent($content);
            }
        }

        // ۱. استخراج دامنه‌های مجاز از لایه پیکربندی رسمی
        $allowedOriginsRaw = (string)config('cors.allowed_origins', '');
        $allowedOrigins = array_filter(array_map('trim', explode(',', $allowedOriginsRaw)));

        // MEDIUM-M-02 Fix: Explicit check for wildcard origins to prevent fail-open security configurations
        if (in_array('*', $allowedOrigins, true)) {
            if (config('app.env') === 'production') {
                throw new \RuntimeException('Wildcard CORS origin is NOT allowed in production environment.');
            }
        }

        // ۲. اضافه کردن دامنه اصلی سایت (Canonical URL)
        $appUrl = trim((string)config('app.url', ''));
        if ($appUrl !== '') {
            $allowedOrigins[] = $appUrl;
        }
        
        // ۳. پاک‌سازی نهایی (حذف اسلش‌های پایانی جهت تطابق صد در صدی با هدر Origin که فاقد اسلش انتهایی است)
        $allowedOrigins = array_map(fn($u) => rtrim((string)$u, '/'), $allowedOrigins);
        $allowedOrigins = array_values(array_unique($allowedOrigins));

        // بررسی هدر ارسال شده از سوی مرورگر (و نرمال‌سازی آن)
        $requestOrigin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
        $isAllowedOrigin = $requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true);

        // هدر Vary برای کش‌های میانی الزامی است
        $response->header('Vary', 'Origin');

        if ($isAllowedOrigin) {
            $response->header('Access-Control-Allow-Origin', $requestOrigin);
            
            // MEDIUM-M5 Fix: Only allow credentials for trusted origins to prevent leak in case of compromised subdomains
            $credentialOrigins = array_filter(array_map('trim', explode(',', (string)config('cors.credential_origins', ''))));
            if (in_array($requestOrigin, $credentialOrigins, true)) {
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
            
            $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept, Origin');
            $response->header('Access-Control-Max-Age', '600');
        } elseif ($isOptions) {
            // MED-06 Fix: If origin is not allowed for an OPTIONS request, return 403 instead of a misleading 204
            $response->status(403);
            $response->setContent('CORS Origin Not Allowed');
        }

        return $response;
    }
}
