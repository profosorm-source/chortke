<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use App\Services\RolePolicy;
use Closure;

/**
 * AdminMiddleware — محدودسازی دسترسی به مدیران سیستم
 */
class AdminMiddleware extends BaseMiddleware
{
    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
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

        $role = (string)($session->get('user_role') ?? '');

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
