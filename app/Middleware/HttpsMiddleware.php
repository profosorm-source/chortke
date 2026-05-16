<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Closure;

/**
 * HttpsMiddleware — اجبار به استفاده از پروتکل امن HTTPS
 */
class HttpsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $env = config('app.env', 'production');

        if ($env === 'production' && !$request->isSecure()) {
            // Obtain authoritative host ONLY from application configuration to prevent Host Header Injection.
            $appUrl = rtrim((string)config('app.url', ''), '/');
            
            if (empty($appUrl)) {
                // Critical Fallback: If APP_URL is missing in prod, use raw server defined host name, NEVER user header.
                $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
                $appUrl = 'https://' . $host;
            }

            $uri = $request->uri();
            // MEDIUM-M-10 Fix: Robust URI sanitization using parse_url to prevent header injection or redirection bypasses
            $cleanPath = parse_url($uri, PHP_URL_PATH) ?: '/';
            $query = parse_url($uri, PHP_URL_QUERY);
            
            $redirectUrl = $appUrl . '/' . ltrim($cleanPath, '/') . ($query ? '?' . $query : '');

            $response = new Response();
            return $response->redirect($redirectUrl, 301);
        }

        return $this->toResponse($next($request));
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result instanceof \Throwable) {
            $response = new Response();
            $response->status(500);
            $response->setContent('Internal Server Error');
            return $response;
        }

        $response = new Response();
        $response->setContent((string)$result);
        return $response;
    }
}
