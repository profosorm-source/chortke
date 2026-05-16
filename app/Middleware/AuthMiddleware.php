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
 */
class AuthMiddleware extends BaseMiddleware
{
    private Session $session;
    private Redis $redis;
    private \App\Services\SettingService $settingService;
    private \App\Models\User $userModel;

    public function __construct(
        Session $session, 
        Redis $redis, 
        \App\Services\SettingService $settingService,
        \App\Models\User $userModel
    ) {
        $this->session = $session;
        $this->redis = $redis;
        $this->settingService = $settingService;
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
        $defaultTimeout = $redisAvailable ? 900 : 300; // 15 min vs 5 min
        $timeout = (int)$this->settingService->get('session_idle_timeout_seconds', $defaultTimeout);
        
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
        
        if ($lastActivity !== null) {
            $lastActivityTime = (int)$lastActivity;
            
            // بررسی انقضای نشست (Idle Timeout)
            if (($now - $lastActivityTime) > $timeout) {
                $session->destroy();
                if ($redisAvailable) {
                    try { $this->redis->delete($redisKey); } catch (\Throwable) {}
                }
                
                $response = new Response();
                if ($request->isAjax()) {
                    return $response->json(['success' => false, 'message' => config('messages.auth.expired')], 401);
                }

                $session->setFlash('error', config('messages.auth.expired'));
                $response->redirect(url('login'));
                return $response;
            }
        }
        
        // تمدید فعالیت در Redis (در صورت در دسترس بودن)
        if ($redisAvailable) {
            try {
                $this->redis->set($redisKey, (string)$now, $timeout + 60);
            } catch (\Throwable) {}
        }

        // HIGH-02 Fix: Always update session as backup to prevent fail-open if Redis goes down
        $session->set('last_activity', (string)$now);

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

            // HIGH-H-06 Fix: Redirect users with pending 2FA to verification page
            if ($session->has(SessionKeys::PENDING_2FA_USER_ID)) {
                $response = new Response();
                $response->redirect(url('verify-2fa'));
                return $response;
            }

            $response = new Response();
            if ($request->isAjax()) {
                return $response->json(['success' => false, 'message' => config('messages.auth.unauthorized')], 401);
            }
            $response->redirect(url('login'));
            return $response;
        }

        // HIGH-H-06 Fix: Periodic DB validation (Every 5 minutes)
        // Ensure user is still active/not banned without hitting DB on every request
        $lastVerify = (int)$session->get('user_verify_time', 0);
        if (time() - $lastVerify > 300) {
            try {
                $user = $this->userModel->find($userId);
                if (!$user || (string)$user->status !== 'active') {
                    $session->destroy();
                    if ($redisAvailable) {
                        try { $this->redis->delete($redisKey); } catch (\Throwable) {}
                    }
                    $response = new Response();
                    if ($request->isAjax()) {
                        return $response->json(['success' => false, 'message' => 'حساب شما غیرفعال شده یا دسترسی با خطا مواجه شد.'], 403);
                    }
                    return $response->redirect(url('login'));
                }
                $session->set('user_verify_time', time());
            } catch (\Throwable $e) {
                $this->logger->error('auth.middleware.db_error', ['error' => $e->getMessage()]);
                $session->destroy();
                $response = new Response();
                return $response->redirect(url('login'));
            }
        }

        return $this->toResponse($next($request));
    }
}
