<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\Permission;
use Core\Session;
use Core\Response;
use Core\Request;
use Closure;

/**
 * PermissionMiddleware — مدیریت سطوح دسترسی کاربران بر اساس Dependency Injection
 */
class PermissionMiddleware extends BaseMiddleware
{
    private Session $session;
    private Permission $permissionModel;

    /**
     * متد سازنده جهت تزریق خودکار وابستگی‌ها (DI Auto-wiring)
     */
    public function __construct(Session $session, Permission $permissionModel)
    {
        $this->session = $session;
        $this->permissionModel = $permissionModel;
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
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }

        $userId = $this->session->get('user_id');
        
        if (!$userId) {
            return false;
        }
        
        $cachedPermissions = $this->session->get('user_permissions');
        $cacheTime = $this->session->get('permissions_cache_time');
        
        // TTL کش مجوزها: ۳۰۰ ثانیه (۵ دقیقه)
        if ($cachedPermissions === null || $cacheTime === null || (time() - (int)$cacheTime) > 300) {
            $cachedPermissions = $this->permissionModel->getUserPermissions((int)$userId);
            $this->session->set('user_permissions', $cachedPermissions);
            $this->session->set('permissions_cache_time', time());
        }
        
        $userRole = $this->session->get('user_role');
        if ($userRole === 'super_admin') {
            return true;
        }
        
        return in_array($permission, (array)$cachedPermissions, true);
    }

    /*
    |--------------------------------------------------------------------------
    | متدهای قدیمی و کمکی سازگاری با گذشته (Legacy & Backward Compatibility Bridge)
    |--------------------------------------------------------------------------
    | متدهای زیر برای کنترلرهایی که هنوز بازنویسی نشده‌اند حفظ شده‌اند تا از هرگونه Breaking Change
    | جلوگیری شود. در آینده پیشنهاد می‌شود دسترسی‌ها از طریق تزریق کلاسی کنترل شوند.
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