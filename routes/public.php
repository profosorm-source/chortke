<?php

/**
 * مسیرهای عمومی — بدون نیاز به احراز هویت
 */

use App\Middleware\CSRFMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Controllers\HomeController;
use App\Controllers\PageController;
use App\Controllers\ContactController;
use App\Controllers\SitemapController;
use App\Controllers\RobotsController;
use App\Controllers\FaviconController;
use App\Controllers\FileController;
use App\Controllers\CaptchaController;
use App\Controllers\TestCaptchaController;
use App\Controllers\BannerController;

$router = app()->router;
$publicForm = [CSRFMiddleware::class, RateLimitMiddleware::class];

// صفحه اصلی
$router->get('/', [HomeController::class, 'index']);

// صفحات ثابت
$router->get('/about',   [PageController::class, 'about']);
$router->get('/contact', [PageController::class, 'contact']);
$router->get('/terms',   [PageController::class, 'terms']);
$router->get('/privacy', [PageController::class, 'privacy']);
$router->get('/help',    [PageController::class, 'help']);
$router->get('/pages/{slug}', [PageController::class, 'show']);

// تماس
$router->post('/contact/send', [ContactController::class, 'send'], $publicForm);

// فایل‌ها
$router->get('/sitemap.xml',  [SitemapController::class, 'index']);
$router->get('/robots.txt',   [RobotsController::class, 'index']);
$router->get('/favicon.ico',  [FaviconController::class, 'index']);
$router->get('/favicon.png',  [FaviconController::class, 'index']);
$router->get('/file/view/{folder}/{filename}', [FileController::class, 'serve'], [\App\Middleware\RateLimitMiddleware::class]);

// بنر (کلیک)
$router->get('/banner/click/{id}', [BannerController::class, 'click']);

// کپچا
$router->get('/captcha/refresh',             [CaptchaController::class, 'refresh']);
$router->get('/captcha/behavioral/ping',     [CaptchaController::class, 'behavioralPing']);
$router->post('/captcha/behavioral/ping',    [CaptchaController::class, 'behavioralPing']);
$router->get('/test-captcha',                [TestCaptchaController::class, 'index']);
$router->post('/test-captcha/verify',        [TestCaptchaController::class, 'verify'], $publicForm);

// جستجوی عمومی (API fingerprint)
$router->post('/api/fingerprint', [\App\Controllers\Api\FingerprintController::class, 'store'], [CSRFMiddleware::class, RateLimitMiddleware::class]);
