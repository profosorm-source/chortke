<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\Permission;
use Core\Session;
use Core\Response;
use Core\Request;
use Closure;
use Core\Redis;
use App\Constants\SessionKeys;

/**
 * PermissionMiddleware — مدیریت سطوح دسترسی کاربران بر اساس Dependency Injection
 */
class PermissionMiddleware extends BaseMiddleware
{
    private Session $session;
    private Permission $permissionModel;
    private Redis $redis;

    /**
     * متد سازنده جهت تزریق خودکار وابستگی‌ها (DI Auto-wiring)
     */
    public function __construct(Session $session, Permission $permissionModel, Redis $redis)
    {
        $this->session = $session;
        $this->permissionModel = $permissionModel;
        $this->redis = $redis;
    }

    /**
     * اجرای Middleware در Pipeline (با استفاده از DI خالص)
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!$this->hasPermission($permission)) {
            $response = new Response();
            
            if ($request->isAjax()) {
                return $response->json([
                    'success' => false,
                    'message' => config('messages.permission.forbidden')
                ], 403);
            }
            
            // رندر صفحه ۴۰۳ به صورت امن
            ob_start();
            view('errors/403');
            $content = ob_get_clean();
            
            $response->setStatusCode(403);
            $response->setContent($content ?: '403 Forbidden');
            return $response;
        }

        return $this->toResponse($next($request));
    }

    /**
     * بررسی دسترسی کاربر با استفاده از نمونه تزریق شده (روش مدرن مبتنی بر DI)
     */
    public function hasPermission(string $permission): bool
    {
        $userId = (int)$this->session->get('user_id');
        
        if ($userId <= 0) {
            return false;
        }

        // 🚀 BUG FIX [C-02]: DB-backed Super Admin check (instance-cached in model)
        // جایگزین چک کردن از سشن که قابل دستکاری بود
        if ($this->permissionModel->isSuperAdmin($userId)) {
            return true;
        }
        
        // MEDIUM-09 Fix: Use Redis for permission caching instead of session for better security and reactivity
        $cacheKey = "user_permissions:{$userId}";
        $cachedPermissions = null;
        
        if ($this->redis->isAvailable()) {
            try {
                $cached = $this->redis->get($cacheKey);
                if ($cached) {
                    $cachedPermissions = json_decode($cached, true);
                }
            } catch (\Throwable) {}
        }
        
        // HIGH-05 Fix: Force DB check for critical permissions to ensure immediate revocation
        $isCritical = $this->isCriticalPermission($permission);
        
        if ($isCritical || $cachedPermissions === null) {
            $cachedPermissions = $this->permissionModel->getUserPermissions($userId);
            
            if (!$isCritical && $this->redis->isAvailable()) {
                try {
                    $this->redis->set($cacheKey, json_encode($cachedPermissions), 30); // 30 seconds TTL
                } catch (\Throwable) {}
            }
        }
        
        return in_array($permission, (array)$cachedPermissions, true);
    }

    /**
     * بررسی آیا این دسترسی از نوع حساس/بحرانی است
     */
    private function isCriticalPermission(string $permission): bool
    {
        $criticalPrefixes = ['admin.', 'finance.', 'security.', 'user.delete', 'system.'];
        foreach ($criticalPrefixes as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | متدهای قدیمی و کمکی سازگاری با گذشته (Legacy & Backward Compatibility Bridge)
    |--------------------------------------------------------------------------
    | متدهای زیر برای کنترلرهایی که هنوز بازنویسی نشده‌اند حفظ شده‌اند تا از هرگونه Breaking Change
    | جلوگیری شود. در آینده پیشنهاد می‌شود دسترسی‌ها از طریق تزریق کلاسی کنترل شوند.
    */

    /**
     * @deprecated به جای متدهای استاتیک از تزریق وابستگی Middleware استفاده کنید.
     */
    public static function check(string $permission): bool
    {
        // هدایت فراخوانی قدیمی به سیستم داینامیک جدید جهت یکپارچه‌سازی و ممیزی راحت کدهای برنامه
        return app(self::class)->hasPermission($permission);
    }
    
    /**
     * بررسی و توقف اگر دسترسی نداشت (برای پشتیبانی از کنترلرهای قدیمی)
     * @deprecated به جای متدهای استاتیک دستی در کنترلر، از پایپ‌لاین روتینگ Middleware چرتکه استفاده کنید.
     */
    public static function require(string $permission): void
    {
        if (!self::check($permission)) {
            // ✅ Fix L1: پرتاب UnauthorizedException به جای exit مستقیم
            // این امکان می‌دهد ExceptionHandler پاسخ متناسب را هندل کند
            throw new \Core\Exceptions\UnauthorizedException('دسترسی غیرمجاز برای انجام این عملیات');
        }
    }

    /**
     * پاک‌سازی کش سطوح دسترسی کاربر در سشن (مثلا هنگام تغییر دسترسی‌ها توسط ادمین)
     * حل خطای Call to undefined method PermissionMiddleware::clearCache در RoleController
     */
    public static function clearCache(): void
    {
        $session = Session::getInstance();
        $session->remove('user_permissions');
        $session->remove('permissions_cache_time');
    }
}