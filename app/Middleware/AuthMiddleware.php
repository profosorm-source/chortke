<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Core\Redis;
use Closure;

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
        $session = $this->session;
        $now = time();
        $timeout = (int)$this->settingService->get('session_idle_timeout_seconds', 900);
        
        // ✅ امنیت: استفاده از Redis برای ذخیره timeout (نه session-side) با فال‌بک امن سشن در صورت عدم دسترسی به ردیس
        $sessionId = session_id();
        $redisKey = "session:activity:" . $sessionId;
        
        $lastActivity = null;
        $redisAvailable = $this->redis->isAvailable();

        if ($redisAvailable) {
            try {
                $lastActivity = $this->redis->get($redisKey);
            } catch (\Throwable) {
                $redisAvailable = false;
            }
        }

        if (!$redisAvailable) {
            $lastActivity = $session->get('last_activity');
        }
        
        if ($lastActivity === null) {
            // اولین بار است، ذخیره کنید
            if ($redisAvailable) {
                $this->redis->set($redisKey, (string)$now, $timeout + 60);
            } else {
                $session->set('last_activity', (string)$now);
            }
        } else {
            $lastActivityTime = (int)$lastActivity;
            
            // بررسی انقضای نشست (Idle Timeout)
            if (($now - $lastActivityTime) > $timeout) {
                $session->destroy();
                if ($redisAvailable) {
                    $this->redis->delete($redisKey);
                }
                
                $response = new Response();
                if ($request->isAjax()) {
                    return $response->json(['success' => false, 'message' => config('messages.auth.expired')], 401);
                }

                $session->setFlash('error', config('messages.auth.expired'));
                $response->redirect(url('login'));
                return $response;
            }
            
            // تمدید فعالیت در Redis یا سشن
            if ($redisAvailable) {
                $this->redis->set($redisKey, (string)$now, $timeout + 60);
            } else {
                $session->set('last_activity', (string)$now);
            }
        }

        // بررسی ورود کاربر
        if (!$session->has('user_id')) {
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
