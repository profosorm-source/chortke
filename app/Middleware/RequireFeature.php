<?php

namespace App\Middleware;

use App\Contracts\LoggerInterface;
use App\Services\FeatureFlagService;
use Core\Request;
use Core\Response;

/**
 * Middleware برای محافظت از Route ها با Feature Flags
 * 
 * مثال استفاده:
 * Route::middleware(['auth', 'feature:crypto_wallet'])->get('/wallet', ...);
 */
class RequireFeature
{
    private FeatureFlagService $featureService;
    private LoggerInterface $logger;
    private Response $response;
    
    public function __construct(
        FeatureFlagService $featureService,
        LoggerInterface $logger,
        Response $response
    ) {
        $this->featureService = $featureService;
        $this->logger = $logger;
        $this->response = $response;
    }
    
    /**
     * Handle the middleware
     * 
     * @param Request $request
     * @param callable $next
     * @param string $feature نام فیچر (مثلا: crypto_wallet)
     * @param string|null $mode حالت: 'redirect' یا 'json' یا '404'
     */
    public function handle(Request $request, callable $next, string $feature, ?string $mode = null)
    {
        $userId = user_id();
        
        // بررسی فیچر
        if (!$this->featureService->isEnabled($feature, $userId)) {
            return $this->handleDisabledFeature($request, $feature, $userId, $mode);
        }
        
        // فیچر فعال است، ادامه بده
        return $next($request);
    }
    
    /**
     * مدیریت فیچر غیرفعال
     */
    private function handleDisabledFeature(Request $request, string $feature, ?int $userId, ?string $mode)
    {
        // تشخیص نوع درخواست
        $isAjax = $request->isAjax();
        $mode = $mode ?? ($isAjax ? 'json' : 'redirect');
        
        // لاگ کردن تلاش دسترسی (🚀 M-02 Fix: Better Attribution)
        $this->logger->warning('feature_flag.access_denied', [
            'channel' => 'feature_flag',
            'feature' => $feature,
            'user_id' => $userId ?: 'guest',
            'ip' => $request->ip(),
            'path' => $request->uri(),
            'method' => $request->method(),
        ]);
        
        // حالت‌های مختلف پاسخ
        switch ($mode) {
            case 'json':
                return $this->response->json([
                    'success' => false,
                    'error' => 'feature_disabled',
                    'message' => 'این ویژگی در حال حاضر غیرفعال است.',
                ], 403);
                
            case '404':
                // در محیط‌های API ممکن است بخواهیم خطای 404 برگردانیم تا وجود مسیر لو نرود
                abort(404);
                return $this->response;
                
            case 'redirect':
            default:
                // 🚀 M-02 Fix: استفاده از تنظیمات به جای هارد-کد
                $fallbackUrl = (string)config('feature_flags.fallback_url', '/dashboard');
                flash_error('این ویژگی در حال حاضر در دسترس نیست.');
                return redirect($fallbackUrl);
        }
    }
}
