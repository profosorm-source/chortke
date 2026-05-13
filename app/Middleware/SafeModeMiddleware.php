<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Closure;

/**
 * SafeModeMiddleware — جلوگیری از تغییرات حساس در حالت Safe Mode
 */
class SafeModeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $isSafeMode = (bool)config('app.safe_mode', false);

        if ($isSafeMode && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            // ✅ Fix L4: لیست سفید مسیرها از کانفیگ لوادشده
            $allowedPaths = config('app.safe_mode_whitelist', ['/login', '/logout', '/verify-2fa']);
            if (!in_array($request->uri(), $allowedPaths)) {
                $response = new Response();
                
                if ($request->isAjax() || str_contains($request->uri(), '/api/')) {
                    return $response->json([
                        'success' => false,
                        'message' => 'سیستم در حالت امن (Safe Mode) قرار دارد. تغییرات مجاز نیست.',
                        'error'   => 'SAFE_MODE_ENABLED'
                    ], 403);
                }

                session()->setFlash('error', 'سیستم در حالت امن قرار دارد و امکان ثبت تغییرات وجود ندارد.');
                return $response->redirect($_SERVER['HTTP_REFERER'] ?? url('/'));
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