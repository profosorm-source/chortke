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
            die(
                '<h2>خطا: vendor/autoload.php پیدا نشد</h2>' .
                '<p>لطفاً دستور زیر را در مسیر پروژه اجرا کنید:</p>' .
                '<pre>composer install</pre>'
            );
        }

        require_once $vendorAutoload;
    }
}