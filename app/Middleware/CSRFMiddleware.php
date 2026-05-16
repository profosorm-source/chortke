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
class CSRFMiddleware extends BaseMiddleware
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
        // HIGH-03 Fix: CSRF Exemption for API and Webhook routes
        $uri = $request->uri();
        if (str_starts_with($uri, '/api/') || str_starts_with($uri, '/webhooks/')) {
            return $this->toResponse($next($request));
        }

        // فقط برای متدهای تغییر دهنده وضعیت
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            try {
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
            } catch (\Throwable $e) {
                $this->logger->error('csrf.check.exception', ['error' => $e->getMessage()]);
                $response = new Response();
                return $response->setStatusCode(419)->setContent('Session Expired');
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