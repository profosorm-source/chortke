<?php
namespace Core;

/**
 * Autoloader
 *
 * تمام autoloading از طریق Composer انجام می‌شود (vendor/autoload.php).
 * این کلاس فقط برای backward-compatibility نگه داشته شده است.
 *
 * در composer.json:
 *   "autoload": {
 *       "psr-4": { "Core\\": "core/", "App\\": "app/" },
 *       "files": ["helpers/functions.php", "helpers/security.php"]
 *   }
 *
 * بعد از هر تغییر در ساختار فایل‌ها:
 *   composer dump-autoload -o
 */
class Autoloader
{
    public static function register(): void
    {
        $vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';

        if (!file_exists($vendorAutoload)) {
            $isCli = (PHP_SAPI === 'cli' || defined('STDIN'));
            $errorMessage = "Error: vendor/autoload.php was not found. Please run 'composer install' in the project root directory.";

            if ($isCli) {
                fwrite(STDERR, "\033[31m" . $errorMessage . "\033[0m\n");
                exit(1);
            }

            // برای وب سرور، پاسخ استاندارد با هدر ۵۰۰ و قالب شیک ارسال می‌کنیم
            http_response_code(500);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            die(
                '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>خطای ساختاری سیستم</title>' .
                '<style>body{font-family:Tahoma,sans-serif;padding:50px;background:#fcfcfc;} .box{background:#fff;border-right:5px solid #e74c3c;padding:30px;box-shadow:0 5px 20px rgba(0,0,0,0.05);border-radius:4px;}</style></head>' .
                '<body><div class="box"><h2>خطای راه‌اندازی: بسته‌های مورد نیاز (Composer) یافت نشدند</h2>' .
                '<p>بسته‌های PHP سیستم نصب نشده‌اند. لطفاً دستور زیر را در ترمینال پروژه اجرا نمایید:</p>' .
                '<pre style="background:#f5f5f5;padding:15px;border-radius:4px;color:#c0392b;font-weight:bold;">composer install</pre></div></body></html>'
            );
        }

        require_once $vendorAutoload;
    }
}