<?php
namespace App\Controllers\Admin;

use App\Controllers\Admin\BaseAdminController;

/**
 * Maintenance Mode Controller
 * 
 * مدیریت حالت تعمیر
 */
class MaintenanceController extends BaseAdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * فعالسازی Maintenance Mode
     */
    public function enable(Request $request, Response $response)
    {
        $this->updateEnvFile('MAINTENANCE_MODE', 'true');
        
        $this->logger->info('Maintenance mode enabled', [
            'by_user' => $this->userId()
        ]);
        
        if (is_ajax()) {
            return $this->response->success('حالت تعمیر فعال شد.');
        }
        
        flash('success', 'حالت تعمیر فعال شد.');
        return $this->response->back();
    }

    /**
     * غیرفعالسازی Maintenance Mode
     */
    public function disable(Request $request, Response $response)
    {
        $this->updateEnvFile('MAINTENANCE_MODE', 'false');
        
        $this->logger->info('Maintenance mode disabled', [
            'by_user' => $this->userId()
        ]);
        
        if (is_ajax()) {
            return $this->response->success('حالت تعمیر غیرفعال شد.');
        }
        
        flash('success', 'حالت تعمیر غیرفعال شد.');
        return $this->response->back();
    }

    /**
     * بروزرسانی فایل .env
     */
    private function updateEnvFile($key, $value)
    {
        $envFile = __DIR__ . '/../../../.env';
        
        if (!file_exists($envFile)) {
            throw new \Exception('.env file not found');
        }
        
        $content = file_get_contents($envFile);
        
        // جستجوی کلید
        $pattern = "/^{$key}=.*/m";
        
        if (preg_match($pattern, $content)) {
            // بروزرسانی
            $content = preg_replace($pattern, "{$key}={$value}", $content);
        } else {
            // افزودن
            $content .= "\n{$key}={$value}";
        }
        
        file_put_contents($envFile, $content);
    }

    /**
     * وضعیت فعلی
     */
    public function status(Request $request, Response $response)
    {
        $isEnabled = env('MAINTENANCE_MODE') === 'true' || env('MAINTENANCE_MODE') === true;
        
        return $this->response->json([
            'success' => true,
            'maintenance_mode' => $isEnabled
        ]);
    }
}