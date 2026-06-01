<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Settings\AppSettings;
use App\Services\Settings\SettingsManager;
use App\Services\UploadService;
use Core\PathResolver;

class SystemSettingController extends BaseAdminController
{
    private AppSettings $appSettings;
    private SettingsManager $settingsManager;
    private UploadService $uploadService;
    private PathResolver $pathResolver;
    
    public function __construct(
        AppSettings $appSettings,
        SettingsManager $settingsManager,
        UploadService $uploadService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->appSettings = $appSettings;
        $this->settingsManager = $settingsManager;
        $this->uploadService  = $uploadService;
        $this->pathResolver   = PathResolver::getInstance();
    }
    
    /**
     * نمایش تنظیمات
     */
    public function index()
    {
        $category = (string)$this->request->get('category', 'general');
        $settings = $this->appSettings->getByCategory($category);
        
        $categories = [
            'general' => 'عمومی',
            'banking' => 'بانکی',
            'task'    => 'تسک‌ها',
            'wallet'  => 'کیف پول',
            'security'=> 'امنیت',
            'contact' => 'تماس',
            'images'  => 'تصاویر و لوگو',
        ];
        
        $this->view('admin/settings/index', [
            'settings' => $settings,
            'categories' => $categories,
            'currentCategory' => $category,
            'title' => 'تنظیمات سیستم'
        ]);
    }
    
    /**
     * بروزرسانی تنظیم
     */
    public function update(): void
    {
        $data = $this->request->body();
        $id    = (int)($data['id'] ?? 0);
        $key   = trim((string)($data['key'] ?? ''));
        $value = (string)($data['value'] ?? '');

        if ($id <= 0 || $key === '') {
            $this->jsonError('درخواست نامعتبر است');
        }

        $oldSetting = $this->appSettings->find($id);
        $oldValue = $oldSetting->value ?? null;

        $ok = $this->settingsManager->updateById($id, $key, $value);

        if (!$ok) {
            $this->jsonError('تنظیمات یافت نشد یا کلید معتبر نیست');
        }

        // Log the change using robust Audit Trail
        $this->auditLog(
            'setting.updated',
            'setting',
            $id,
            ['key' => $key, 'value' => $oldValue],
            ['key' => $key, 'value' => $value]
        );

        $this->appSettings->clearCache();

        if (function_exists('settings')) {
            settings(true);
        }

        $this->jsonSuccess('تنظیمات ذخیره شد');
    }

    /**
     * آپلود تصویر برای تنظیمات
     */
    public function uploadImage(): void
    {
        if (!$this->request->hasFile('image')) {
            $this->jsonError('فایلی آپلود نشده است');
        }
        
        $settingId = (int)$this->request->post('setting_id', 0);
        $setting = $this->appSettings->find($settingId);
        
        if (!$setting || (($setting->group ?? '') !== 'images' && $setting->type !== 'image')) {
            $this->jsonError('تنظیم یافت نشد یا نوع آن تصویر نیست', [], 404);
        }
        
        try {
            // حذف تصویر قبلی با استفاده از PathResolver
            if (!empty($setting->value)) {
                $oldPath = $this->pathResolver->public($setting->value);
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            
            // آپلود فایل جدید با استفاده از UploadService
            $result = $this->uploadService->upload($this->request->file('image'), 'site-images', [
                'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/x-icon'],
                'max_size' => 2 * 1024 * 1024
            ]);
            
            if (!$result['success']) {
                $this->jsonError($result['message'] ?? 'خطا در آپلود');
            }
            
            $imagePath = $result['path'];
            
            // بروزرسانی در دیتابیس
            $updated = $this->settingsManager->updateValueById($settingId, $imagePath);
            
            if (!$updated) {
                throw new \Exception('خطا در ذخیره اطلاعات در دیتابیس');
            }

            // Log the change using robust Audit Trail
            $this->auditLog(
                'setting.image_uploaded',
                'setting',
                $settingId,
                ['key' => $setting->key ?? null, 'value' => $setting->value ?? null],
                ['key' => $setting->key ?? null, 'value' => $imagePath]
            );
            
            $this->appSettings->clearCache();
            
            $this->jsonSuccess('تصویر با موفقیت آپلود شد', [
                'url' => url($imagePath),
                'path' => $imagePath
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('admin.settings.upload_failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا در آپلود تصویر: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * حذف تصویر
     */
    public function removeImage(): void
    {
        $data = $this->request->body();
        $settingId = (int)($data['setting_id'] ?? 0);
        
        $setting = $this->appSettings->find($settingId);
        if (!$setting) {
            $this->jsonError('تنظیم یافت نشد', [], 404);
        }
        
        try {
            if (!empty($setting->value)) {
                $fullPath = $this->pathResolver->public($setting->value);
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
            
            $this->settingsManager->updateValueById($settingId, '');

            // Log the change using robust Audit Trail
            $this->auditLog(
                'setting.image_removed',
                'setting',
                $settingId,
                ['key' => $setting->key ?? null, 'value' => $setting->value ?? null],
                ['key' => $setting->key ?? null, 'value' => '']
            );

            $this->appSettings->clearCache();
            
            $this->jsonSuccess('تصویر با موفقیت حذف شد');
            
        } catch (\Exception $e) {
            $this->jsonError('خطا در حذف تصویر: ' . $e->getMessage(), [], 500);
        }
    }
}