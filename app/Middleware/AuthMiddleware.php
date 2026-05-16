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

    public function __construct(Session $session, Redis $redis, \App\Services\SettingService $settingService)
    {
        $this->session = $session;
        $this->redis = $redis;
        $this->settingService = $settingService;
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
        $timeout = (int)$this->settingService->get('session_idle_timeout_seconds', 900);
        
        // ✅ امنیت: استفاده از Redis برای ذخیره timeout (نه session-side) با فال‌بک امن سشن در صورت عدم دسترسی به ردیس
        $sessionId = session_id();
        $redisKey = "session:activity:" . $sessionId;
        
        $lastActivity = null;
        $redisAvailable = $this->redis->isAvailable();

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
        
        // تمدید فعالیت
        $activityUpdated = false;
        if ($redisAvailable) {
            try {
                $this->redis->set($redisKey, (string)$now, $timeout + 60);
                $activityUpdated = true;
            } catch (\Throwable) {
                $redisAvailable = false;
            }
        }
        
        if (!$activityUpdated) {
            $session->set('last_activity', (string)$now);
        }

        // بررسی ورود کاربر
        // MED-08 Fix: Unified and robust check for both user_id and logged_in flag
        $userId = (int)$session->get(SessionKeys::USER_ID, 0);
        if ($userId <= 0 || !$session->get(SessionKeys::LOGGED_IN)) {
            $response = new Response();
            if ($request->isAjax()) {
                return $response->json(['success' => false, 'message' => config('messages.auth.unauthorized')], 401);
            }
            $response->redirect(url('login'));
            return $response;
        }

        return $this->toResponse($next($request));
    }
}
