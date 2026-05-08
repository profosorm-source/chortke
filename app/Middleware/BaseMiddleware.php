<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Response;

/**
 * BaseMiddleware — کلاس پایه انتزاعی برای به اشتراک‌گذاری کدهای تکراری میان‌افزارها
 */
abstract class BaseMiddleware
{
    /**
     * کپسوله‌سازی پاسخ برگشتی جهت یکپارچگی خروجی‌ها (حل تکرار کد در ۱۴ فایل Middleware)
     */
    protected function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        
        $response = new Response();
        $response->setContent((string)$result);
        return $response;
    }
}
