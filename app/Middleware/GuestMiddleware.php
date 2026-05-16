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
        if ($this->session->get(SessionKeys::LOGGED_IN)) {
            $response = new Response();
            $response->redirect(url('dashboard'));
            return $response;
        }

        // MEDIUM-09 Fix: Redirect users with pending 2FA to verification page
        if ($this->session->has(SessionKeys::PENDING_2FA_USER_ID)) {
            $response = new Response();
            $response->redirect(url('verify-2fa'));
            return $response;
        }

        return $this->toResponse($next($request));
    }
}
