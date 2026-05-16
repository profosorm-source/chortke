<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Closure;
use App\Constants\SessionKeys;

/**
 * GuestMiddleware — محدودسازی دسترسی فقط برای کاربران مهمان (وارد نشده)
 */
class GuestMiddleware extends BaseMiddleware
{
    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // CRITICAL-C3 Fix: Consistent check using SessionKeys and LOGGED_IN flag
        // HIGH-07 Fix: Double check both flag and user_id to prevent session partial state bypass
        if ($this->session->get(SessionKeys::LOGGED_IN) && (int)$this->session->get(SessionKeys::USER_ID) > 0) {
            $response = new Response();
            $response->redirect(url('dashboard'));
            return $response;
        }

        // MEDIUM-09 Fix: Redirect users with pending 2FA to verification page (avoid loop)
        if ($this->session->has(SessionKeys::PENDING_2FA_USER_ID) && $request->uri() !== '/verify-2fa') {
            $response = new Response();
            $response->redirect(url('verify-2fa'));
            return $response;
        }

        return $this->toResponse($next($request));
    }
}
