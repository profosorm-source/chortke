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
        $env = config('app.env', env('APP_ENV', 'production'));

        if ($env === 'production' && !$request->isSecure()) {
            // استفاده از APP_URL به جای اعتماد به هدر HTTP_HOST کاربر
            $appUrl = rtrim(config('app.url', env('APP_URL', '')), '/');
            
            if (empty($appUrl)) {
                // اگر تنظیم نشده بود، به عنوان راهکار امنیتی به لوکال هاست یا دامنه فعلی (با فیلتر شدید) برمی‌گردیم
                $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
                $appUrl = 'https://' . $host;
            }

            $uri = $request->uri();
            $redirectUrl = $appUrl . ($uri === '/' ? '' : $uri);

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

        $response = new Response();
        $response->setContent((string)$result);
        return $response;
    }
}
