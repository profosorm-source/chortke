<?php
namespace Core;

/**
 * CSRF Protection
 *
 * از Container می‌خواند — نه app() مستقیم
 */
class CSRF
{
    private Session $session;
    private Request $request;

    public function __construct(Session $session, Request $request)
    {
        $this->session = $session;
        $this->request = $request;
    }

    public function generateToken(): string
    {
        if (!$this->session->has('_csrf_token')) {
            $this->session->set('_csrf_token', bin2hex(random_bytes(32)));
        }
        return $this->session->get('_csrf_token');
    }

    // CORE-036: Action-specific CSRF tokens for enhanced security on destructive actions
    public function generateTokenFor(string $action): string
    {
        $key = '_csrf_token_' . hash('sha256', $action);
        if (!$this->session->has($key)) {
            $this->session->set($key, bin2hex(random_bytes(32)));
        }
        return $this->session->get($key);
    }

    public function verifyTokenFor(string $action, ?string $token): bool
    {
        $key = '_csrf_token_' . hash('sha256', $action);
        $sessionToken = $this->session->get($key);
        if (!$sessionToken || !$token) {
            return false;
        }
        $isValid = hash_equals($sessionToken, $token);
        if ($isValid) {
            $this->session->remove($key); // One-time usage
        }
        return $isValid;
    }

    public function getToken(): ?string
    {
        return $this->session->get('_csrf_token');
    }

    public function verify(?string $token): bool
    {
        $sessionToken = $this->getToken();
        if (!$sessionToken || !$token) return false;
        
        // MED-04 Fix: Check Origin/Referer for sensitive requests
        if (!$this->validateOrigin()) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * MED-04 Fix: Validate Origin or Referer against application URL
     */
    private function validateOrigin(): bool
    {
        $appUrl = config('app.url');
        if (!$appUrl || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
            return false; // Fail closed if application URL is missing or invalid
        }

        $origin = $this->request->header('Origin');
        $referer = $this->request->header('Referer');
        
        // Enforce Origin/Referer presence in production to prevent bypasses
        if (config('app.env') === 'production' && !$origin && !$referer) {
            $appHost = parse_url($appUrl, PHP_URL_HOST);
            if (in_array($appHost, ['localhost', '127.0.0.1'], true)) {
                return true; // Local development may not always send Origin/Referer reliably.
            }
            return false;
        }

        // Parse host from app.url
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if ($origin) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            if ($originHost !== $appHost) return false;
        }

        if ($referer) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            if ($refererHost !== $appHost) return false;
        }
        
        return true;
    }

    public function check(): bool
    {
        if (!in_array($this->request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return true;
        }
        $tokenName = config('csrf.token_name') ?? '_token';
        // M16 Fix: پشتیبانی کامل از هدرهای کلاینت‌های Vue.js و Axios با چک کردن X-XSRF-TOKEN به عنوان جایگزین
        $token = $this->request->input($tokenName)
                 ?? $this->request->header('X-CSRF-TOKEN')
                 ?? $this->request->header('X-XSRF-TOKEN')
                 // BACKWARD-COMPAT: Some legacy forms still use _token or csrf_token names.
                 ?? $this->request->input('_token')
                 ?? $this->request->input('csrf_token');
        return $this->verify($token);
    }

    public function validate(): void
    {
        if (!$this->check()) {
            if (function_exists('logger')) {
                try {
                    logger()->warning('CSRF token validation failed', [
                        'channel' => 'security',
                        'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                        'uri' => $this->request->uri(),
                        'method' => $this->request->method(),
                    ]);
                } catch (\Throwable $e) {
                    // ignore logging failure
                }
            }

            // ✅ Fix L1: پرتاب SecurityException به جای exit مستقیم
            // این امکان می‌دهد ExceptionHandler بتواند پاسخ متناسب را هندل کند
            throw new \Core\Exceptions\SecurityException(
                'CSRF token validation failed',
                403
            );
        }
    }

    public function regenerate(): string
    {
        $this->session->remove('_csrf_token');
        return $this->generateToken();
    }
}
