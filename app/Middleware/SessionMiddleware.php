<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Closure;

class SessionMiddleware extends BaseMiddleware
{
    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $this->session->start();
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                try {
                    logger()->error('Session failed to start in middleware', ['error' => $e->getMessage()]);
                } catch (\Throwable $ignore) {}
            }
            if (config('app.debug') || config('app.env') === 'local') {
                throw $e;
            }
        }
        return $next($request);
    }
}
