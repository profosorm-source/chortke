<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Closure;
use App\Constants\SessionKeys;

/**
 * SecurityHeadersMiddleware — اعمال هدرهای امنیتی به تمام پاسخ‌ها
 */
class SecurityHeadersMiddleware
{
    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // اطمینان از بازگشت آبجکت Response
        if (!$response instanceof Response) {
            $content = (string)$response;
            $response = new Response();
            $response->setContent($content);
        }

        $env = config('app.env', 'production');
        $nonce = $this->generateNonce($request);

        // Content Security Policy
        $csp = $this->buildCSP($env, $nonce);
        $response->header('Content-Security-Policy', $csp);
        
        // جلوگیری از حملات رایج
        $response->header('X-Frame-Options', 'SAMEORIGIN');
        $response->header('X-Content-Type-Options', 'nosniff');
        // MED-05 Fix: X-XSS-Protection is deprecated and can be used as an attack vector in old browsers.
        // Modern CSP is sufficient.
        $response->header('X-XSS-Protection', '0');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // سیاست‌های دسترسی به سخت‌افزار
        $permissionsPolicy = 'camera=(), microphone=(), geolocation=(self), payment=(self)';
        $response->header('Permissions-Policy', $permissionsPolicy);
        
        // HSTS (فقط در پروادکشن و HTTPS)
        if ($env === 'production' && $request->isSecure()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        
        // Cross-Origin Policies (اصلاح شده برای جلوگیری از شکستن CDNها)
        // require-corp فقط اگر واقعاً نیاز به ایزوله‌سازی پردازش باشد اعمال شود
        $response->header('Cross-Origin-Opener-Policy', 'same-origin');
        $response->header('Cross-Origin-Resource-Policy', 'same-site'); 

        // LOW-02 Fix: Remove framework identification for security through obscurity
        $response->header('Server', '');
        
        return $response;
    }
    
    private function buildCSP(string $env, string $nonce): string
    {
        // Synchronized whitelisted sources from previous hardcoded index configuration.
        $scripts = "'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://code.jquery.com https://www.google.com https://www.gstatic.com";
        $styles = "'self' 'nonce-{$nonce}' https://fonts.googleapis.com https://cdn.jsdelivr.net";
        
        return implode('; ', [
            "default-src 'self'",
            "script-src {$scripts}",
            "style-src {$styles}",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "frame-src https://www.google.com",
            "connect-src 'self' https://www.google.com",
            "upgrade-insecure-requests"
        ]);
    }
    
    private function generateNonce(Request $request): string
    {
        // HIGH-03 Fix: Nonce must be per-request. Storing in Session reduces entropy and increases leak risk.
        $nonce = base64_encode(random_bytes(16));
        $request->setAttribute(SessionKeys::CSP_NONCE, $nonce);
        
        // Ensure backward compatibility with legacy layout defines for current request only
        if (!defined('CSP_NONCE')) {
            define('CSP_NONCE', $nonce);
        }
        
        return $nonce;
    }
}
