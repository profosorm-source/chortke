<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use App\Policies\RolePolicy;
use Closure;

/**
 * AdminMiddleware — محدودسازی دسترسی به مدیران سیستم
 */
class AdminMiddleware extends BaseMiddleware
{
    private Session $session;
    private \App\Models\User $userModel;

    public function __construct(Session $session, \App\Models\User $userModel)
    {
        $this->session = $session;
        $this->userModel = $userModel;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->session;

        if (!$session->has('user_id')) {
            $response = new Response();
            if ($request->isAjax()) {
                return $response->json(['success' => false, 'message' => 'لطفاً ابتدا وارد شوید.'], 401);
            }
            $session->setFlash('error', 'لطفاً ابتدا وارد حساب کاربری خود شوید.');
            return $response->redirect(url('login'));
        }

        $userId = (int)$session->get('user_id');
        $role = (string)($session->get('user_role') ?? '');
        $lastVerify = (int)$session->get('admin_verify_time');

        // 🚀 BUG FIX [H-01]: Periodic DB re-validation (Every 5 minutes)
        // جلوگیری از دسترسی ادمین‌های اخراج شده یا تغییر نقش یافته
        if (time() - $lastVerify > 300) {
            $user = $this->userModel->find($userId);
            if (!$user || !RolePolicy::isAdmin($user->role_slug ?? '')) {
                $session->destroy();
                $response = new Response();
                if ($request->isAjax()) {
                    return $response->json(['success' => false, 'message' => 'دسترسی شما منقضی شده است.'], 403);
                }
                return $response->redirect(url('login'));
            }
            
            // Sync session with DB
            $session->set('user_role', $user->role_slug);
            $session->set('admin_verify_time', time());
            $role = $user->role_slug;
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
