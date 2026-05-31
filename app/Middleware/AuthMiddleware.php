<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Core\Redis;
use Closure;
use App\Constants\SessionKeys;

/**
 * AuthMiddleware — مدیریت احراز هویت و انقضای نشست کاربر
 * 
 * SECURITY NOTES:
 * - When Redis is unavailable, timeout is reduced for security
 * - Session verification includes Redis keys cleanup
 * - Fail-closed behavior when all storage mechanisms fail
 */
class AuthMiddleware extends BaseMiddleware
{
    private Session $session;
    private Redis $redis;
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Models\User $userModel;

    // LOW-04 Fix: Reduced fallback timeout from 300 (5 min) to 180 (3 min)
    // This provides more aggressive security when Redis is unavailable
    private const FALLBACK_TIMEOUT_WHEN_REDIS_DOWN = 180; // 3 minutes instead of 5

    public function __construct(
        Session $session, 
        Redis $redis, 
        \App\Services\Settings\AppSettings $appSettings,
        \App\Models\User $userModel
    ) {
        $this->session = $session;
        $this->redis = $redis;
        $this->appSettings = $appSettings;
        $this->userModel = $userModel;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // CORE-037: Enforce separate API authentication (Cookie auth not permitted on /api/*)
        if (str_starts_with($request->uri(), '/api/')) {
            $response = new Response();
            return $response->json([
                'success' => false, 
                'message' => 'احراز هویت مبتنی بر سشن روی وب‌سرویس‌ها مجاز نیست.'
            ], 401);
        }
        $session = $this->session;
        $now = time();

        $redisAvailable = $this->redis && $this->redis->isAvailable();
        
        // MEDIUM-M2 Fix: Reduce timeout when Redis is down for conservative security posture
        // LOW-04 Fix: Further reduced from 300 (5min) to 180 (3min) when Redis is down
        $defaultTimeout = $redisAvailable ? 900 : self::FALLBACK_TIMEOUT_WHEN_REDIS_DOWN; // 15 min vs 3 min
        $timeout = (int)$this->appSettings->get('session_idle_timeout_seconds', $defaultTimeout);
        
        // If Redis was available but then fails during this request, use conservative timeout
        // This ensures we don't trust stale activity data from Redis
        if ($redisAvailable) {
            try {
                // Verify Redis is still responding (not just that it's "available")
                $this->redis->ping();
            } catch (\Throwable $e) {
                $redisAvailable = false;
                $timeout = self::FALLBACK_TIMEOUT_WHEN_REDIS_DOWN; // Use conservative timeout
            }
        }
        
        // ✅ امنیت: استفاده از Redis برای ذخیره timeout (نه session-side) با فال‌بک امن سشن در صورت عدم دسترسی به ردیس
        $sessionId = session_id();
        $redisKey = "session:activity:" . $sessionId;
        
        $lastActivity = null;

        // MED-08 Fix: Unified activity handling with robust fallbacks
        try {
            if ($redisAvailable) {
                $lastActivity = $this->redis->get($redisKey);
            }
        } catch (\Throwable) {
            $redisAvailable = false;
        }

        if (!$redisAvailable || $lastActivity === null) {
            $lastActivity = $session->get('last_activity');
        }
        
        if ($lastActivity === null) {
            // NEW-H-03 Fix: Initialize last_activity if missing to prevent timeout bypass
            $session->set('last_activity', (string)$now);
            // LOW-04 Fix: When initializing, also set Redis key if available
            if ($redisAvailable) {
                try { $this->redis->set($redisKey, (string)$now, $timeout + 60); } catch (\Throwable) {}
            }
        } else {
            $lastActivityTime = (int)$lastActivity;
            
            // بررسی انقضای نشست (Idle Timeout)
            if (($now - $lastActivityTime) > $timeout) {
                // LOW-04 Fix: Clean up Redis keys when session expires
                if ($redisAvailable) {
                    try { 
                        $this->redis->delete($redisKey); 
                        // LOW-04 Fix: Also clear verify key
                        $userId = (int)$session->get(SessionKeys::USER_ID, 0);
                        if ($userId > 0) {
                            $this->redis->delete("user_verify:{$userId}");
                        }
                    } catch (\Throwable) {}
                }
                
                $session->destroy();
                
                $response = new Response();
                if ($request->isAjax()) {
                    return $response->json(['success' => false, 'message' => config('messages.auth.expired')], 401);
                }

                $session->setFlash('error', config('messages.auth.expired'));
                $response->redirect(url('login'));
                return $response;
            }
        }
        
        // تمدید فعالیت در Redis و Session فقط در صورتی که حداقل 30 ثانیه از آخرین تمدید گذشته باشد
        // جهت کاهش سربار و بهینه‌سازی Write Amplification
        $lastActivityTime = isset($lastActivity) ? (int)$lastActivity : 0;
        if ($lastActivityTime === 0 || ($now - $lastActivityTime) >= 30) {
            // تمدید فعالیت در Redis (در صورت در دسترس بودن)
            if ($redisAvailable) {
                try {
                    $oldValue = $this->redis->getSet($redisKey, (string)$now);
                    $this->redis->expire($redisKey, $timeout + 60);
                    if ($oldValue !== null) {
                        $lastActivityTime = (int)$oldValue;
                    }
                } catch (\Throwable) {
                    // If Redis write fails, continue with session-side tracking
                    $redisAvailable = false;
                }
            }

            // HIGH-02 Fix: Always update session as backup to prevent fail-open if Redis goes down
            $session->set('last_activity', (string)$now);
        }

        // CRITICAL-05 Fix: Check for pending 2FA state BEFORE normal auth check
        // This prevents users with pending 2FA from bypassing it if LOGGED_IN is true
        if ($session->has(SessionKeys::PENDING_2FA_USER_ID)) {
            $response = new Response();
            $response->redirect(url('verify-2fa'));
            return $response;
        }

        // بررسی ورود کاربر
        // MED-08 Fix: Unified and robust check for both user_id and logged_in flag
        $userId = (int)$session->get(SessionKeys::USER_ID, 0);
        if ($userId <= 0 || !$session->get(SessionKeys::LOGGED_IN)) {
            // HIGH-H-13 Fix: Redirect to verification page if an email confirmation is pending
            if ($session->has('pending_verification_email')) {
                $response = new Response();
                $response->redirect(url('email/verify-code'));
                return $response;
            }

            $response = new Response();
            if ($request->isAjax()) {
                return $response->json(['success' => false, 'message' => config('messages.auth.unauthorized')], 401);
            }
            $response->redirect(url('login'));
            return $response;
        }

        // HIGH-H-06 Fix: Periodic DB validation (Every 2 minutes)
        // HIGH-04 Fix: Using Redis for verification timestamp to prevent session-side manipulation
        $verifyRedisKey = "user_verify:{$userId}";
        $lastVerify = 0;
        if ($redisAvailable) {
            try { $lastVerify = (int)$this->redis->get($verifyRedisKey); } catch (\Throwable) {}
        }
        if ($lastVerify === 0) {
            $lastVerify = (int)$session->get('user_verify_time', 0);
        }

        if (time() - $lastVerify > 120) { // Shortened to 2 minutes
            try {
                $user = $this->userModel->find($userId);
                if (!$user || (string)$user->status !== 'active') {
                    // LOW-04 Fix: Clean up all session-related Redis keys on account deactivation
                    if ($redisAvailable) {
                        try { 
                            $this->redis->delete($redisKey); 
                            $this->redis->delete($verifyRedisKey);
                        } catch (\Throwable) {}
                    }
                    $session->destroy();
                    $response = new Response();
                    if ($request->isAjax()) {
                        return $response->json(['success' => false, 'message' => 'حساب شما غیرفعال شده یا دسترسی با خطا مواجه شد.'], 403);
                    }
                    return $response->redirect(url('login'));
                }
                
                $now = time();
                $session->set('user_verify_time', $now);
                if ($redisAvailable) {
                    try { $this->redis->set($verifyRedisKey, (string)$now, 300); } catch (\Throwable) {}
                }
            } catch (\Throwable $e) {
                $this->logger->error('auth.middleware.db_error', ['error' => $e->getMessage()]);
                // LOW-04 Fix: When DB verification fails, use fail-closed behavior
                // Don't allow the request to proceed if we can't verify the user is still valid
                $session->destroy();
                if ($redisAvailable) {
                    try { 
                        $this->redis->delete($redisKey); 
                        $this->redis->delete($verifyRedisKey);
                    } catch (\Throwable) {}
                }
                $response = new Response();
                if ($request->isAjax()) {
                    return $response->json(['success' => false, 'message' => 'خطا در تأیید وضعیت حساب. لطفاً دوباره وارد شوید.'], 401);
                }
                return $response->redirect(url('login'));
            }
        }

        return $this->toResponse($next($request));
    }
}