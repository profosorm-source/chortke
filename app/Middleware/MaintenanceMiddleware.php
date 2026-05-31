<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use App\Services\Settings\AppSettings;
use Closure;

/**
 * MaintenanceMiddleware — مدیریت حالت تعمیرات سایت
 */
class MaintenanceMiddleware
{
    private AppSettings $setting;

    public function __construct(AppSettings $setting)
    {
        $this->setting = $setting;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $maintenanceMode = false;
        $allowedIPs = [];
        $message = 'سایت در حال بروزرسانی است...';

        try {
            $maintenanceMode = (bool)$this->setting->get('maintenance_mode', config('maintenance.enabled', false));
            $allowedIPs = (array)$this->setting->get('maintenance_allowed_ips', config('maintenance.allowed_ips', []));
            $message = (string)$this->setting->get('maintenance_message', config('maintenance.message', 'سایت در حال بروزرسانی است...'));
        } catch (\Throwable $e) {
            $maintenanceMode = (bool)config('maintenance.enabled', false);
            $allowedIPs = (array)config('maintenance.allowed_ips', []);
            $message = (string)config('maintenance.message', 'سایت در حال بروزرسانی است...');
        }
        
        if (!$maintenanceMode) {
            return $this->toResponse($next($request));
        }

        // بررسی مسیرهای استثنا شده (Except paths)
        $uri = $request->uri();
        $excepts = (array)config('maintenance.except', []);
        foreach ($excepts as $except) {
            if ($except === '/' && $uri === '/') {
                return $this->toResponse($next($request));
            }
            if ($except !== '/' && str_starts_with($uri, $except)) {
                return $this->toResponse($next($request));
            }
        }
        
        // استثناء برای ادمین‌ها
        if (function_exists('is_admin') && is_admin()) {
            return $this->toResponse($next($request));
        }
        
        // استثناء برای IPهای مجاز (Strict check)
        $clientIP = $request->ip();
        
        if (in_array($clientIP, $allowedIPs, true)) {
            return $this->toResponse($next($request));
        }
        
        $response = new Response();
        $response->setStatusCode(503);
        
        // رندر ویو در قالب استرینگ برای قرارگیری در آبجکت Response
        ob_start();
        view('errors/maintenance', ['message' => $message]);
        $content = ob_get_clean();
        
        $response->setContent($content ?: 'Site is under maintenance.');
        return $response;
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
