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
 * 
 * SECURITY NOTES:
 * - CSP nonce is set as a response header (in addition to request attribute)
 * - This ensures CSP can be enforced even if views use output buffering
 * - All security headers are set atomically to prevent partial exposure
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
        // LOW-02 Fix: Generate nonce at the START of the request pipeline
        // This ensures the nonce is available for all code that runs during $next($request)
        $nonce = $this->generateNonce();
        
        // Store in request attribute for view access (backward compatibility)
        $request->setAttribute(SessionKeys::CSP_NONCE, $nonce);
        
        // Execute the request
        $response = $next($request);

        // Ensure we have a Response object
        if (!$response instanceof Response) {
            $content = (string)$response;
            $response = new Response();
            $response->setContent($content);
        }

        // Apply security headers
        $env = config('app.env', 'production');

        // Content Security Policy with nonce
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
        // LOW-01 Fix: Using credentialless for COEP to avoid breaking cross-origin resources (like CDNs)
        $response->header('Cross-Origin-Embedder-Policy', 'credentialless');

        // LOW-02 Fix: Remove framework identification for security through obscurity
        $response->header('Server', '');
        
        // LOW-L-02 Fix: Modern CSP reporting
        $reportUrl = (string)config('app.url', '') . '/api/security/csp-report';
        $response->header('Reporting-Endpoints', 'csp-endpoint="' . $reportUrl . '"');
        $response->header('Report-To', json_encode([
            'group' => 'csp-group',
            'max_age' => 10886400,
            'endpoints' => [['url' => $reportUrl]]
        ]));
        
        // LOW-02 Fix: Add Cache-Control for sensitive pages
        if ($this->isSensitivePage($request->uri())) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
        }
        
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
            "img-src 'self' data: https://*.google.com https://*.gstatic.com https://cdn.jsdelivr.net",
            "frame-src https://www.google.com",
            "connect-src 'self' https://www.google.com",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "upgrade-insecure-requests",
            "report-uri /api/security/csp-report",
            "report-to csp-group"
        ]);
    }
    
    private function generateNonce(): string
    {
        // HIGH-03 Fix: Nonce must be per-request. Using cryptographically secure random bytes.
        // This is generated fresh for each request and stored in request attribute
        // for access in views and set as response header for verification.
        $nonce = base64_encode(random_bytes(16));
        
        // Also store in session for verification (optional, for debugging)
        // Don't store in session for production as it could be leaked via session fixation
        // $this->session->set('_csp_nonce', $nonce);
        
        return $nonce;
    }
    
    /**
     * Check if the current page is sensitive and should have cache disabled
     */
    private function isSensitivePage(string $uri): bool
    {
        $sensitivePaths = [
            '/login',
            '/register',
            '/password/reset',
            '/dashboard',
            '/settings',
            '/admin',
            '/profile',
            '/2fa',
            '/verify',
            '/payment',
            '/withdrawal',
        ];
        
        foreach ($sensitivePaths as $path) {
            if (str_starts_with($uri, $path)) {
                return true;
            }
        }
        
        return false;
    }
}