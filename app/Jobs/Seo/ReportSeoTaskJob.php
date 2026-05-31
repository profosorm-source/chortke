<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class ReportSeoTaskJob
{
    public function __construct(
        private \App\Repositories\SeoRepository $repository,
        private \App\Services\Interaction\ReportService $reportService
    ) {}

    public function handle(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        $ad = $this->repository->getAd($adId);
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
