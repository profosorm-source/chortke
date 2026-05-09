<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Closure;

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

        $env = config('app.env', env('APP_ENV', 'production'));
        $nonce = $this->generateNonce();

        // Content Security Policy
        $csp = $this->buildCSP($env, $nonce);
        $response->header('Content-Security-Policy', $csp);
        
        // جلوگیری از حملات رایج
        $response->header('X-Frame-Options', 'SAMEORIGIN');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-XSS-Protection', '1; mode=block');
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

        // حذف هدرهای افشاکننده
        header_remove('X-Powered-By');
        $response->header('Server', 'Chortke');
        
        return $response;
    }
    
    private function buildCSP(string $env, string $nonce): string
    {
        $scripts = "'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com";
        $styles = "'self' 'nonce-{$nonce}' https://fonts.googleapis.com https://cdn.jsdelivr.net";
        
        if ($env === 'development') {
            $styles .= " 'unsafe-inline'";
        }
        
        return implode('; ', [
            "default-src 'self'",
            "script-src {$scripts}",
            "style-src {$styles}",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: https: blob:",
            "connect-src 'self' https://api.chortke.ir",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "upgrade-insecure-requests"
        ]);
    }
    
    private function generateNonce(): string
    {
        $nonce = base64_encode(random_bytes(16));
        $this->session->set('csp_nonce', $nonce);
        $_SESSION['csp_nonce'] = $nonce;
        return $nonce;
    }
}
