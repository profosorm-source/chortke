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

    public function getToken(): ?string
    {
        return $this->session->get('_csrf_token');
    }

    public function verify(?string $token): bool
    {
        $sessionToken = $this->getToken();
        if (!$sessionToken || !$token) return false;
        return hash_equals($sessionToken, $token);
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
                 ?? $this->request->header('X-XSRF-TOKEN');
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
