<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\LoggerInterface;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Closure;

/**
 * CSRFMiddleware — محافظت در برابر حملات CSRF
 */
class CSRFMiddleware
{
    private LoggerInterface $logger;
    private CSRF $csrf;

    public function __construct(LoggerInterface $logger, CSRF $csrf)
    {
        $this->logger = $logger;
        $this->csrf = $csrf;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // فقط برای متدهای تغییر دهنده وضعیت
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            if (!$this->csrf->check()) {
                $this->logger->warning('security.csrf_failed', [
                    'ip' => get_client_ip(),
                    'uri' => $request->uri()
                ]);

                $response = new Response();
                if ($request->isAjax()) {
                    return $response->json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 419);
                }

                return $response->setStatusCode(419)->setContent('419 Page Expired - CSRF Token mismatch');
            }
        }

        $result = $next($request);
        
        if ($result instanceof Response) {
            return $result;
        }

        $response = new Response();
        $response->setContent((string)$result);
        return $response;
    }
}