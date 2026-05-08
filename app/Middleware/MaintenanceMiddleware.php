<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use App\Models\SystemSetting;
use Closure;

/**
 * MaintenanceMiddleware — مدیریت حالت تعمیرات سایت
 */
class MaintenanceMiddleware
{
    private SystemSetting $setting;

    public function __construct(SystemSetting $setting)
    {
        $this->setting = $setting;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $maintenanceMode = (bool)$this->setting->get('maintenance_mode', false);
        
        if (!$maintenanceMode) {
            return $this->toResponse($next($request));
        }
        
        // استثناء برای ادمین‌ها
        if (function_exists('is_admin') && is_admin()) {
            return $this->toResponse($next($request));
        }
        
        // استثناء برای IPهای مجاز (Strict check)
        $allowedIPs = (array)$this->setting->get('maintenance_allowed_ips', []);
        $clientIP = get_client_ip();
        
        if (in_array($clientIP, $allowedIPs, true)) {
            return $this->toResponse($next($request));
        }
        
        // نمایش صفحه تعمیرات
        $message = (string)$this->setting->get('maintenance_message', 'سایت در حال بروزرسانی است...');
        
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