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
 * 
 * SECURITY NOTES:
 * - Critical permissions always bypass cache (TTL=0) for immediate revocation
 * - Non-critical permissions have reduced TTL (60s) to balance performance and security
 * - HMAC integrity protection on cached permissions
 */
class PermissionMiddleware extends BaseMiddleware
{
    private Session $session;
    private Permission $permissionModel;
    private Redis $redis;
    private \App\Contracts\LoggerInterface $logger;

    // MEDIUM-M-15 Fix: Reduced TTL from 300 to 60 seconds for non-critical permissions
    // This ensures permission changes are reflected within 1 minute (was 5 minutes)
    // Critical permissions always bypass cache (TTL=0)
    private const NON_CRITICAL_TTL = 60;  // 60 seconds (reduced from 300)
    private const CRITICAL_TTL = 0;       // No cache for critical permissions

    /**
     * متد سازنده جهت تزریق خودکار وابستگی‌ها (DI Auto-wiring)
     */
    public function __construct(Session $session, Permission $permissionModel, Redis $redis, \App\Contracts\LoggerInterface $logger)
    {
        $this->session = $session;
        $this->permissionModel = $permissionModel;
        $this->redis = $redis;
        $this->logger = $logger;
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
        
        // HIGH-05 Fix: Use Redis for permission caching with HMAC integrity protection
        $cacheKey = "user_permissions:{$userId}";
        $cachedPermissions = null;
        $appKey = secure_key();
        
        if ($this->redis->isAvailable()) {
            try {
                $cached = $this->redis->get($cacheKey);
                if ($cached && strpos($cached, '|') !== false) {
                    [$mac, $data] = explode('|', $cached, 2);
                    if (hash_equals($mac, hash_hmac('sha256', $data, $appKey))) {
                        $cachedPermissions = json_decode($data, true);
                    } else {
                        $this->logger->critical('permission.cache_tampered', ['user_id' => $userId]);
                    }
                }
            } catch (\Throwable) {}
        }
        
        // HIGH-05 Fix: Force DB check for critical permissions to ensure immediate revocation
        $isCritical = $this->isCriticalPermission($permission);
        
        if ($isCritical || $cachedPermissions === null) {
            $cachedPermissions = $this->permissionModel->getUserPermissions($userId);
            
            if ($this->redis->isAvailable()) {
                try {
                    $data = json_encode($cachedPermissions);
                    $mac = hash_hmac('sha256', $data, $appKey);
                    
                    // MEDIUM-M-15 Fix: Use reduced TTL for non-critical permissions (60s instead of 300s)
                    // This ensures permission changes are reflected within 1 minute
                    // Critical permissions are not cached at all (handled above in the if condition)
                    $ttl = $isCritical ? self::CRITICAL_TTL : self::NON_CRITICAL_TTL;
                    
                    if ($ttl > 0) {
                        $this->redis->set($cacheKey, $mac . '|' . $data, $ttl);
                    }
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

    public static function check(string $permission): bool
    {
        // HIGH-05 Fix: Remove static instance caching to prevent privilege escalation in persistent environments
        // This ensures the permission check is fresh and request-scoped by instantiating a new instance.
        return (new self(
            app(\Core\Session::class),
            app(\App\Models\Permission::class),
            app(\Core\Redis::class),
            app(\App\Contracts\LoggerInterface::class)
        ))->hasPermission($permission);
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
     * MEDIUM-M1 Fix: Use Redis for cache invalidation to match hasPermission logic
     */
    public function clearUserCache(int $userId): void
    {
        if ($this->redis->isAvailable()) {
            try {
                // MEDIUM-M-09 Fix: Consistent key usage for cache invalidation
                $this->redis->delete("user_permissions:{$userId}");
            } catch (\Throwable) {}
        }
    }

    /**
     * Static wrapper for backward compatibility
     */
    public static function clearCache(int $userId): void
    {
        $instance = new self(
            app(\Core\Session::class),
            app(\App\Models\Permission::class),
            app(\Core\Redis::class),
            app(\App\Contracts\LoggerInterface::class)
        );
        $instance->clearUserCache($userId);
    }
}