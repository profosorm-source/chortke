<?php

namespace App\Controllers;

use Core\Database;

class FaviconController extends BaseController
{
    /**
     * نمایش favicon داینامیک SVG
     * اگر favicon در تنظیمات آپلود نشده باشد،
     * یک آیکون SVG با حرف اول اسم سایت نمایش می‌دهد
     */
    public function index(): void
    {
        // اگر favicon آپلود شده redirect کن
        $faviconPath = setting('site_favicon');
        if ($faviconPath) {
            $fullPath = BASE_PATH . '/public/' . ltrim($faviconPath, '/');
            if (file_exists($fullPath)) {
                $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                $mime = match($ext) {
                    'ico'  => 'image/x-icon',
                    'svg'  => 'image/svg+xml',
                    'webp' => 'image/webp',
                    default => 'image/png',
                };
                header('Content-Type: ' . $mime);
                header('Cache-Control: public, max-age=86400');
                readfile($fullPath);
                exit;
            }
        }

        // Fallback: SVG داینامیک با حرف اول اسم سایت
        $siteName = setting('site_name', 'چ');
        $letter   = mb_substr($siteName, 0, 1, 'UTF-8');
        $color    = setting('site_primary_color', '#1565c0');

        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=3600');

        echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">
  <rect width="32" height="32" rx="6" fill="{$color}"/>
  <text x="16" y="23" font-family="Vazirmatn, Tahoma, Arial" font-size="18"
        font-weight="bold" fill="white" text-anchor="middle">{$letter}</text>
</svg>
SVG;
        exit;
    }
}
