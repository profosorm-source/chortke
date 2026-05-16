<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Database;
use Core\RateLimiter;
use Closure;

/**
 * ApiAuthMiddleware — احراز هویت و کنترل دسترسی API
 */
class ApiAuthMiddleware extends BaseMiddleware
{
    private Database $db;
    private RateLimiter $rateLimiter;

    public function __construct(Database $db, RateLimiter $rateLimiter)
    {
        $this->db = $db;
        $this->rateLimiter = $rateLimiter;
    }

    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return $this->errorResponse('توکن API ارائه نشده', 401, 'MISSING_TOKEN');
        }

        $user = $this->validateToken($token, 0); // Validate token first without ownership check
        if (!$user) {
            return $this->errorResponse('توکن نامعتبر یا منقضی شده', 401, 'INVALID_TOKEN');
        }

        // HIGH-08 Fix: Extract user_id from the validated token, not from the request body/query
        $requestingUserId = (int)$user->id;
        
        // HIGH-05 Fix: Enforce ownership check for sensitive paths
        if ($this->isSensitivePath($request->uri())) {
            $requestedUserId = (int)$request->get('user_id', 0);
            if ($requestedUserId > 0 && $requestedUserId !== $requestingUserId) {
                return $this->errorResponse('دسترسی غیرمجاز: ناهماهنگی در شناسه کاربری', 403, 'OWNERSHIP_VIOLATION');
            }
        }

        $allowedStatuses = ['active', '1', 1];
        if (!in_array($user->status, $allowedStatuses, true)) {
            return $this->errorResponse('حساب کاربری غیرفعال است', 403, 'ACCOUNT_DISABLED');
        }

        // ✅ بررسی اسکوپ‌های مورد نیاز (Scope Enforcement)
        if (!empty($requiredScopes)) {
            $tokenScopes = array_filter(explode(',', (string)($user->scopes ?? '')));
            foreach ($requiredScopes as $scope) {
                if (!in_array($scope, $tokenScopes, true) && !in_array('*', $tokenScopes, true)) {
                    return $this->errorResponse('توکن شما اجازه دسترسی به این بخش را ندارد (اسکوپ مورد نیاز: ' . $scope . ')', 403, 'INSUFFICIENT_SCOPE');
                }
            }
        }

        // Rate Limiting
        $rateLimitResult = $this->checkRateLimit($user->id);
        if (!$rateLimitResult['allowed']) {
            return $this->rateLimitResponse($rateLimitResult);
        }

        $request->setUser($user);

        // بروزرسانی آمار استفاده از توکن
        // MEDIUM-01 Fix: Add error handling for stats update
        try {
            $this->db->query(
                "UPDATE api_tokens SET last_used_at = NOW(), use_count = use_count + 1 WHERE id = ?",
                [(int)$user->token_id]
            );
        } catch (\Throwable $e) {
            // Ignore non-critical error but log it
            if (function_exists('logger')) {
                logger()->warning('api_auth.stats_update_failed', [
                    'token_id' => $user->token_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $response = $this->toResponse($next($request));
        
        // MED-04 Fix: Add token expiry header for client-side proactive management
        if (!empty($user->expires_at)) {
            $response->setHeader('X-Token-Expires-At', (string)$user->expires_at);
        }
        
        return $response;
    }
    
    private function checkRateLimit(int $userId): array
    {
        $key = 'api:user:' . $userId;
        $maxAttempts = (int)config('rate_limits.api.authenticated.max_attempts', 200);
        $decayMinutes = (int)config('rate_limits.api.authenticated.decay_minutes', 1);

        if (!$this->rateLimiter->attempt($key, $maxAttempts, $decayMinutes)) {
            return [
                'allowed' => false,
                'retry_after' => $this->rateLimiter->availableIn($key),
                'limit' => $maxAttempts,
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function rateLimitResponse(array $result): Response
    {
        $response = new Response();
        $response->setHeader('Retry-After', (string)($result['retry_after'] ?? 60));
        $response->setHeader('X-RateLimit-Limit', (string)($result['limit'] ?? 200));
        $response->setHeader('X-RateLimit-Remaining', '0');
        $response->json([
            'success' => false,
            'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است.',
            'error'   => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $result['retry_after'] ?? 60,
        ], 429);
        return $response;
    }

    private function extractToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization') 
            ?? $request->header('authorization');

        if (!preg_match('/Bearer\s+(.+)/i', (string)$authHeader, $m)) {
            return null;
        }

        $token = trim($m[1]);
        return preg_match('/^[a-f0-9]{64}$/i', $token) ? $token : null;
    }

    private function validateToken(string $token, int $requestingUserId = 0): ?object
    {
        $secret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
        if (!$secret || strlen($secret) < 32) {
            throw new \RuntimeException('SECURITY_API_TOKEN_SECRET is not configured or too weak');
        }
        $hashedToken = hash_hmac('sha256', $token, $secret);
        
        // ✅ امنیت: اگر requestingUserId فراهم شد، مالکیت توکن را بررسی کن
        $query = "SELECT u.*, at.id AS token_id, at.scopes
                  FROM api_tokens at
                  JOIN users u ON u.id = at.user_id
                  WHERE at.token = ? 
                    AND (at.expires_at IS NULL OR at.expires_at > NOW()) 
                    AND at.revoked = 0";
        
        $params = [$hashedToken];
        
        if ($requestingUserId > 0) {
            $query .= " AND at.user_id = ?";
            $params[] = $requestingUserId;
        }
        
        $query .= " LIMIT 1";
        
        return $this->db->fetch($query, $params) ?: null;
    }

    /**
     * بررسی آیا مسیر جاری حساس است و نیاز به مالکیت مستقیم دارد
     * 
     * Fix M2: مسیرهای حساس مانند پرداخت، برداشت و تغییر حساب
     * باید مالکیت توکن بر اساس user_id اجباری باشد
     */
    private function isSensitivePath(string $uri): bool
    {
        // HIGH-H4 Fix: Expanded and configurable list of sensitive paths requiring strict ownership
        $sensitivePaths = config('security.api.sensitive_paths', [
            '/api/payment',
            '/api/withdrawal',
            '/api/wallet',
            '/api/account',
            '/api/profile/update',
            '/api/admin',
            '/api/security',
            '/api/2fa',
            '/api/token',
        ]);

        foreach ($sensitivePaths as $path) {
            if (str_starts_with($uri, (string)$path)) {
                return true;
            }
        }

        return false;
    }

    private function errorResponse(string $message, int $code, string $errorType): Response
    {
        $response = new Response();
        return $response->json([
            'success' => false,
            'message' => $message,
            'error'   => $errorType,
        ], $code);
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        $response = new Response();
        $response->setContent((string)$result);
        return $response;
    }
}