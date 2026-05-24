<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Middleware;

/**
 * ApiVersionMiddleware
 * 
 * لایه مدیریت ورژن‌های API سیستم
 * مدیریت سازگاری برای عدم شکستن تغییرات در سمت اپلیکیشن‌های کلاینت
 */
class ApiVersionMiddleware extends Middleware
{
    private string $defaultVersion;
    private array $supportedVersions;

    public function __construct()
    {
        $this->defaultVersion = config('api.default_version', 'v1');
        $this->supportedVersions = config('api.supported_versions', ['v1', 'v2']);
    }

    public function handle(): void
    {
        // 1. بررسی هدر Accept-Version
        $requestedVersion = $_SERVER['HTTP_ACCEPT_VERSION'] ?? null;

        // 2. بررسی آدرس (مثلا /api/v1/...) اگر هدر ارسال نشده باشد
        if (!$requestedVersion) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if (preg_match('#^/api/(v\d+)/#', $uri, $matches)) {
                $requestedVersion = $matches[1];
            }
        }

        // اگر ورژن مشخص نشده بود، نسخه دیفالت را اعمال می‌کنیم
        if (!$requestedVersion) {
            $requestedVersion = $this->defaultVersion;
        }

        // بررسی اینکه آیا ورژن درخواستی هنوز پشتیبانی می‌شود؟
        if (!in_array($requestedVersion, $this->supportedVersions, true)) {
            http_response_code(400); // Bad Request
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'UNSUPPORTED_API_VERSION',
                    'message' => "The API version '{$requestedVersion}' is no longer supported.",
                    'supported_versions' => $this->supportedVersions
                ]
            ]);
            exit;
        }

        // ثبت ورژن درخواست شده برای استفاده در کنترلرها
        $_SERVER['API_VERSION'] = $requestedVersion;
    }
}
