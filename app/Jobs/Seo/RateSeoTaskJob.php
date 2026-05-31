<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class RateSeoTaskJob
{
    public function __construct(
        private \App\Repositories\SeoRepository $repository,
        private \App\Services\Interaction\RatingService $ratingService
    ) {}

    public function handle(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        $ad = $this->repository->getAd($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        $stars = max(1, min(5, $stars));

        try {
            $ok = $this->ratingService->rate(
                $raterId,
                'seo_task',
                $adId,
                \App\Enums\ModuleContext::GLOBAL,
                $stars
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز'];
            }

            return ['success' => true, 'message' => 'امتیاز با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
}
