<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\AntiFraud\BrowserFingerprintService;

/**
 * FingerprintController
 * 
 * دریافت و پردازش browser fingerprint
 */
class FingerprintController extends BaseApiController
{
    private BrowserFingerprintService $fingerprintService;
    
    public function __construct(BrowserFingerprintService $fingerprintService)
    {
        parent::__construct();
        $this->fingerprintService = $fingerprintService;
    }
    
    /**
     * دریافت و ذخیره fingerprint
     */
    public function store(): void
    {
        // در APIهای عمومی، اگر کاربر لاگین بود آیدی‌اش را برمی‌داریم، در غیر این صورت ۰ یا نال
        $userId = $this->userId();
        
        $data = $this->request->body();
        
        if (empty($data)) {
            $this->error('داده‌های فینگرپرینت خالی است', 400);
        }

        // تولید fingerprint
        $fingerprint = $this->fingerprintService->generate($data);
        
        // ذخیره (اگر کاربر لاگین بود)
        if ($userId > 0) {
            $this->fingerprintService->store($userId, $fingerprint, $data);
            
            // تحلیل
            $analysis = $this->fingerprintService->analyze($userId, $fingerprint);
            
            // لاگ کردن در صورت مشکوک بودن
            if ($analysis['suspicious'] ?? false) {
                $this->fingerprintService->logAnalysis($userId, $fingerprint, $analysis);
            }

            $this->success([
                'fingerprint' => substr($fingerprint, 0, 16) . '...',
                'suspicious' => $analysis['suspicious'] ?? false
            ]);
            return;  // ← Critical: Exit after response to prevent double response
        }

        // برای کاربران مهمان فقط تولید و بازگشت می‌دهیم (بدون ذخیره دیتابیسی سنگین یا با منطق متفاوت)
        $this->success([
            'fingerprint' => substr($fingerprint, 0, 16) . '...',
            'message' => 'Guest fingerprint processed'
        ]);
    }
}
