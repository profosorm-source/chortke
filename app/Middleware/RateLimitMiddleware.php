<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\RateLimiter;
use Core\Session;
use Closure;
use App\Contracts\LoggerInterface;

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
        $this->maxAttempts = (int)env('RATE_LIMIT_DEFAULT_MAX', $maxAttempts);
        $this->decayMinutes = (int)env('RATE_LIMIT_DEFAULT_DECAY', $decayMinutes);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $calledNext = false;
        try {
            [$maxAttempts, $decayMinutes] = $this->resolveLimit($request);
            $key = $this->resolveRequestSignature($request);

            if (!$this->rateLimiter->attempt($key, $maxAttempts, $decayMinutes)) {
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
            $response->json([
                'success' => false,
                'message' => 'خطای امنیتی سیستمی در اعتبارسنجی درخواست.'
            ], 500);
            return $response;
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
        $userId = $this->session->get('user_id');
        if ($userId) {
            return 'rl_user_' . $userId . '_' . md5($request->uri());
        }
        return 'rl_ip_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '_' . md5($request->uri());
    }
}
