<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Closure;

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
        if ($this->session->has('user_id')) {
            $response = new Response();
            $response->redirect(url('dashboard'));
            return $response;
        }

        return $this->toResponse($next($request));
    }
}
