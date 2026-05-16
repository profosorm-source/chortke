<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\CaptchaService;
use Core\Request;
use Core\Response;
use Closure;

/**
 * CaptchaMiddleware — بررسی کد امنیتی برای فرم‌های حساس
 */
class CaptchaMiddleware
{
    private CaptchaService $captchaService;

    public function __construct(CaptchaService $captchaService)
    {
        $this->captchaService = $captchaService;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->captchaService->isEnabled() || $request->method() !== 'POST') {
            return $this->toResponse($next($request));
        }

        if (!verify_captcha()) {
            $response = new Response();

            if ($request->isAjax()) {
                return $response->json([
                    'success' => false,
                    'message' => 'کد امنیتی اشتباه است. لطفاً دوباره تلاش کنید.',
                ], 422);
            }

            session()->setFlash('error', 'کد امنیتی اشتباه است.');
            session()->setFlash('old', $request->all());

            $referer = $_SERVER['HTTP_REFERER'] ?? url('/');
            $redirectUrl = (function_exists('is_safe_redirect') && is_safe_redirect($referer)) ? $referer : url('/');

            return $response->redirect($redirectUrl);
        }

        return $this->toResponse($next($request));
    }

    /**
     * اطمینان از اینکه نتیجه حتماً یک آبجکت Response است
     */
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