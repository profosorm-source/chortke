<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class ReportSeoTaskJob
{
    private \App\Services\Seo\AdsSeoService $adsService;
    private \App\Services\Interaction\ReportService $reportService;
    public function __construct(
        \App\Services\Seo\AdsSeoService $adsService,
        \App\Services\Interaction\ReportService $reportService
    ) {        $this->adsService = $adsService;
        $this->reportService = $reportService;
}

    public function handle(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        $ad = $this->adsService->getAd($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        try {
            $ok = $this->reportService->submit(
                $reporterId,
                'seo_task',
                $adId,
                \App\Enums\ModuleContext::GLOBAL,
                $reason,
                $description
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت گزارش'];
            }

            return ['success' => true, 'message' => 'گزارش با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
}
