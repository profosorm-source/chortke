<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\RateLimiter;
use Core\Session;
use Closure;
use App\Contracts\LoggerInterface;
use App\Constants\SessionKeys;

/**
 * RateLimitMiddleware — محدودسازی نرخ درخواست‌ها
 */
class RateLimitMiddleware
{
    private RateLimiter $rateLimiter;
    private LoggerInterface $logger;
    private Session $session;
    private int $maxAttempts;
    private int $decayMinutes;

    /**
     * تنظیمات پیش‌فرض برای مسیرهای خاص
     */
    private const ROUTE_LIMITS = [
        '/login'            => [5,   5],
        '/admin/login'      => [3,  10],
        '/register'         => [3,  30],
        '/forgot-password'  => [3,  60],
        '/reset-password'   => [3,  60],
        '/payment'          => [10,  1],
        '/withdrawal'       => [5,  60],
        '/api/'             => [100, 1],
    ];

    public function __construct(RateLimiter $rateLimiter, LoggerInterface $logger, Session $session, int $maxAttempts = 60, int $decayMinutes = 1)
    {
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;
        $this->session = $session;
        $this->maxAttempts = (int)config('rate_limits.default.max_attempts', $maxAttempts);
        $this->decayMinutes = (int)config('rate_limits.default.decay_minutes', $decayMinutes);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $calledNext = false;
        try {
            [$maxAttempts, $decayMinutes] = $this->resolveLimit($request);
            
            // CORE-042: Use high-precision token_bucket for sensitive critical operations
            $isCriticalPath = false;
            $requestUri = $request->uri();
            foreach (array_keys(self::ROUTE_LIMITS) as $pattern) {
                if (str_contains($requestUri, $pattern)) {
                    $isCriticalPath = true;
                    break;
                }
            }

            $originalStrategy = $this->rateLimiter->getStrategy();
            if ($isCriticalPath) {
                $this->rateLimiter->setStrategy('token_bucket');
            }

            $key = $this->resolveRequestSignature($request);

            try {
                $allowed = $this->rateLimiter->attempt($key, $maxAttempts, $decayMinutes);
            } finally {
                $this->rateLimiter->setStrategy($originalStrategy);
            }

            if (!$allowed) {
                $retryAfter = $this->rateLimiter->availableIn($key);

                $this->logger->warning('Rate limit exceeded', [
                    'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'uri'         => $request->uri(),
                    'retry_after' => $retryAfter,
                ]);

                $response = new Response();
                $response->setHeader('Retry-After', (string)$retryAfter);
                $response->setHeader('X-RateLimit-Limit', (string)$maxAttempts);
                $response->setHeader('X-RateLimit-Remaining', '0');
                $response->json([
                    'success'     => false,
                    'message'     => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً ' . $retryAfter . ' ثانیه دیگر صبر کنید.',
                    'retry_after' => $retryAfter,
                ], 429);
                return $response;
            }

            $calledNext = true;
            $response = $next($request);

            // اطمینان از بازگشت آبجکت Response
            if (!$response instanceof Response) {
                $content = (string)$response;
                $response = new Response();
                $response->setContent($content);
            }

            $remaining = max(0, $maxAttempts - $this->rateLimiter->hits($key));
            
            return $response
                ->header('X-RateLimit-Limit', (string)$maxAttempts)
                ->header('X-RateLimit-Remaining', (string)$remaining);

        } catch (\Throwable $e) {
            $this->logger->error('middleware.rate_limit.error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            if ($calledNext) {
                throw $e;
            }
            
            // Fail-Closed: در صورت بروز هرگونه خطای داخلی در سیستم محدودسازی نرخ، درخواست را به طور ایمن رد می‌کنیم
            $response = new Response();
            return $response->json([
                'success' => false,
                'message' => 'سرویس موقتاً در دسترس نیست. لطفا دقایقی دیگر تلاش کنید.'
            ], 503);
        }
    }

    private function resolveLimit(Request $request): array
    {
        $uri = $request->uri();
        foreach (self::ROUTE_LIMITS as $pattern => $limits) {
            if (str_contains($uri, $pattern)) {
                return $limits;
            }
        }
        return [$this->maxAttempts, $this->decayMinutes];
    }

    private function resolveRequestSignature(Request $request): string
    {
        $uri = $request->uri() ?? '';
        $cleanUri = strtok($uri, '?') ?: $uri; // فقط path، بدون query parameters
        
        // CORE-043: Normalize components (trim, lowercase) to ensure hash uniqueness
        $cleanUri = strtolower(trim($cleanUri));
        
        $userId = $this->session->get(SessionKeys::USER_ID);
        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if ($userId) {
            // HIGH-04 Fix: Combined IP + UserID for authenticated users to prevent scraping/DDoS 
            // even with a valid account.
            return 'rl_user_' . $userId . '_' . md5($ip) . '_' . md5($cleanUri);
        }
        
        // MEDIUM-06 Fix: Normalize IPv6 to /64 prefix to prevent rate limit bypass
        $normalizedIp = $this->normalizeIp($ip);
        return 'rl_ip_' . md5($normalizedIp) . '_' . md5($cleanUri);
    }

    /**
     * MEDIUM-06 Fix: Normalize IPv6 address to its /64 prefix
     */
    private function normalizeIp(string $ip): string 
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed === false) return $ip;
            $packed = substr($packed, 0, 8) . str_repeat("\x00", 8); // /64 mask
            return inet_ntop($packed);
        }
        return $ip;
    }
}
