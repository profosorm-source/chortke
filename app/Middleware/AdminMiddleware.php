<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use App\Policies\RolePolicy;
use Closure;
use App\Constants\SessionKeys;

/**
 * AdminMiddleware — محدودسازی دسترسی به مدیران سیستم
 */
class AdminMiddleware extends BaseMiddleware
{
    private \App\Contracts\LoggerInterface $logger;
    private \App\Models\User $userModel;

    public function __construct(Session $session, \App\Models\User $userModel, \App\Contracts\LoggerInterface $logger)
    {
        $this->session = $session;
        $this->userModel = $userModel;
        $this->logger = $logger;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->session;

        // CRITICAL-NEW-02 Fix: Add explicit 2FA pending check to prevent session confusion bypasses
        if ($session->has(SessionKeys::PENDING_2FA_USER_ID)) {
            if ($session->get('admin_pending_2fa')) {
                $response = new Response();
                $response->redirect(url('/admin/verify-2fa'));
                return $response;
            }
            // Non-admin pending 2FA should not access admin area
            $session->destroy();
            $response = new Response();
            $response->redirect(url('login'));
            return $response;
        }

        if (!$session->has(SessionKeys::USER_ID) || !$session->get(SessionKeys::LOGGED_IN)) {
            $response = new Response();
            if ($request->isAjax()) {
                return $response->json(['success' => false, 'message' => 'لطفاً ابتدا وارد شوید.'], 401);
            }
            $session->setFlash('error', 'لطفاً ابتدا وارد حساب کاربری خود شوید.');
            return $response->redirect(url('login'));
        }

        $userId = (int)$session->get(SessionKeys::USER_ID);
        $role = (string)($session->get(SessionKeys::USER_ROLE) ?? '');
        // HIGH-09 Fix: Use Redis for admin_verify_time instead of session to prevent manipulation
        $redis = app(\Core\Redis::class);
        $redisAvailable = $redis && $redis->isAvailable();
        $verifyRedisKey = "admin_verify:{$userId}";
        
        $lastVerify = 0;
        if ($redisAvailable) {
            try { $lastVerify = (int)$redis->get($verifyRedisKey); } catch (\Throwable) {}
        }
        if ($lastVerify === 0) {
            $lastVerify = (int)$session->get('admin_verify_time', 0);
        }

        // 🚀 BUG FIX [H-01]: Periodic DB re-validation (Every 15 seconds for enhanced role revocation)
        if (time() - $lastVerify > 15) {
            try {
                $user = $this->userModel->find($userId);
                if (!$user || !RolePolicy::isAdmin($user->role ?? '')) {
                    $session->destroy();
                    if ($redisAvailable) {
                        try { 
                            $redis->delete($verifyRedisKey);
                            $redis->delete("session:activity:" . session_id()); 
                        } catch (\Throwable) {}
                    }
                    $response = new Response();
                    if ($request->isAjax()) {
                        return $response->json(['success' => false, 'message' => 'دسترسی شما منقضی یا محدود شده است.'], 403);
                    }
                    return $response->redirect(url('login'));
                }
                
                // Sync session with DB and refresh flags
                $session->set(SessionKeys::USER_ROLE, $user->role);
                $session->set(SessionKeys::LOGGED_IN, true);
                
                $now = time();
                $session->set('admin_verify_time', $now);
                if ($redisAvailable) {
                    try { $redis->set($verifyRedisKey, (string)$now, 600); } catch (\Throwable) {}
                }
                
                $role = $user->role;
            } catch (\Throwable $e) {
                $this->logger->error('admin.middleware.db_error', ['error' => $e->getMessage()]);
                $session->destroy();
                $response = new Response();
                return $response->redirect(url('login'));
            }
        }

        if (!RolePolicy::isAdmin($role)) {
            $response = new Response();
            if ($request->isAjax()) {
                return $response->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
            }
            
            ob_start();
            view('errors/403');
            $content = ob_get_clean();
            
            $response->setStatusCode(403);
            $response->setContent($content ?: '403 Forbidden');
            return $response;
        }

        return $this->toResponse($next($request));
    }
}
