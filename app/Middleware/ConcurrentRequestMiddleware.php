<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Redis;
use Closure;

/**
 * ConcurrentRequestMiddleware — جلوگیری از ثبت همزمان و مضاعف درخواست‌ها
 */
class ConcurrentRequestMiddleware extends BaseMiddleware
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = session('user_id');

        // قفل گذاری فقط برای متدهایی که تغییر دهنده هستند (POST, PUT, DELETE, PATCH)
        if ($userId && in_array(strtoupper($request->method()), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            if ($this->redis && $this->redis->isAvailable()) {
                $uriHash = md5($request->uri());
                $lockKey = "lock:user:{$userId}:{$uriHash}";

                // تلاش برای ایجاد قفل غیرهمزمان با انقضای ۳ ثانیه
                // در کتابخانه Redis افزونه php، متد set با پارامترهای ['nx', 'ex' => 3] کار می‌کند
                try {
                    $acquired = $this->redis->getClient()->set($lockKey, '1', ['nx', 'ex' => 3]);
                    if (!$acquired) {
                        $response = new Response();
                        if ($request->isAjax()) {
                            return $response->json([
                                'success' => false,
                                'message' => 'درخواست قبلی شما در حال پردازش است. لطفا چند لحظه صبر کنید.'
                            ], 429);
                        }
                        
                        $response->setContent('درخواست همزمان مجاز نیست. لطفا صبر کنید.');
                        $response->status(429);
                        return $response;
                    }
                } catch (\Throwable $e) {
                    // در صورت خطای ردیس، برای تداوم اجرای برنامه عملیات را متوقف نمی‌کنیم (Fail-open برای ردیس در این بخش)
                }
            }
        }

        try {
            $response = $next($request);
        } finally {
            // در پایان درخواست، قفل آزاد می‌شود
            if (isset($lockKey) && $this->redis && $this->redis->isAvailable()) {
                try {
                    $this->redis->getClient()->del($lockKey);
                } catch (\Throwable $e) {
                    // نادیده گرفتن خطا
                }
            }
        }

        return $this->toResponse($response);
    }
}
