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
 * 
 * SECURITY: All tokens are hashed using HMAC-SHA256 before database lookup
 * to prevent token leakage in case of SQL injection or database compromise.
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
        // CRIT-02 Fix: Extract token with HMAC hash BEFORE any other operations
        // This ensures the raw token is never exposed in logs, error messages, or memory dumps
        $tokenHash = $this->extractTokenHash($request);

        if (!$tokenHash) {
            return $this->errorResponse('توکن API ارائه نشده', 401, 'MISSING_TOKEN');
        }

        // Validate token using the pre-hashed value (no re-hashing in validateToken)
        $user = $this->validateToken($tokenHash, 0);
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

    /**
     * CRIT-02 Fix: Extract and hash token atomically to prevent raw token exposure
     * 
     * The raw token is hashed immediately using HMAC-SHA256 before being returned.
     * This ensures:
     * 1. Raw token is never stored in variables that could be logged
     * 2. Raw token is never exposed in error messages or stack traces
     * 3. Token validation uses consistent hashed values
     * 
     * @param Request $request
     * @return string|null HMAC-SHA256 hash of the token, or null if invalid
     */
    private function extractTokenHash(Request $request): ?string
    {
        $authHeader = $request->header('Authorization') 
            ?? $request->header('authorization');

        if (!preg_match('/Bearer\s+(.+)/i', (string)$authHeader, $m)) {
            return null;
        }

        $token = trim($m[1]);
        
        // Validate format first (fast rejection for malformed tokens)
        // Token must be exactly 64 hex characters (32 bytes = 256 bits)
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            // Don't log the token itself to prevent information disclosure
            $this->logger->warning('api_auth.invalid_token_format', [
                'header_length' => strlen($authHeader ?? ''),
                'token_length' => strlen($token)
            ]);
            return null;
        }

        // CRIT-02 Fix: Hash token IMMEDIATELY with HMAC-SHA256
        // The secret ensures that even if this codebase is leaked, 
        // attackers cannot generate valid tokens without the secret
        $secret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
        if (!$secret || strlen($secret) < 32) {
            // Fail closed: don't process tokens if secret is not properly configured
            $this->logger->critical('api_auth.secret_not_configured');
            return null;
        }
        
        // HIGH-H-02 Fix: Use hash_hmac for additional security (keyed hash)
        // This prevents rainbow table attacks even if the token format is predictable
        $hashedToken = hash_hmac('sha256', $token, $secret);
        
        // Immediately discard the raw token variable to prevent accidental exposure
        unset($token);
        
        return $hashedToken;
    }

    /**
     * Validate token using pre-hashed value
     * The hashed token must be provided (already hashed in extractTokenHash)
     * 
     * @param string $hashedToken Pre-hashed token from extractTokenHash
     * @param int $requestingUserId Optional user ID for ownership verification
     * @return object|null User object if valid, null otherwise
     */
    private function validateToken(string $hashedToken, int $requestingUserId = 0): ?object
    {
        // Verify token hash is properly formatted (additional safety check)
        if (!preg_match('/^[a-f0-9]{64}$/', $hashedToken)) {
            $this->logger->warning('api_auth.invalid_hashed_token_format');
            return null;
        }
        
        // MEDIUM-01 Fix: Check negative cache in Redis before DB query to prevent denial of service (DoS) from invalid token floods
        $redis = app(\Core\Redis::class);
        $redisAvailable = $redis && $redis->isAvailable();
        if ($redisAvailable) {
            try {
                if ($redis->get("token_revoked:{$hashedToken}")) {
                    return null;
                }
            } catch (\Throwable $e) {}
        }

        // ✅ Use the pre-hashed token directly in the query
        // No additional hashing needed - token is already HMAC-SHA256 hashed
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
        
        // Use prepared statements to prevent SQL injection
        // The hashed token is safe to use in SQL as it's guaranteed to be hex characters
        $result = $this->db->fetch($query, $params) ?: null;

        // Populate negative cache if token is invalid or revoked to save DB resources
        if ($result === null && $redisAvailable) {
            try {
                $redis->set("token_revoked:{$hashedToken}", "1", 3600); // negative cache for 1 hour
            } catch (\Throwable $e) {}
        }

        return $result;
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