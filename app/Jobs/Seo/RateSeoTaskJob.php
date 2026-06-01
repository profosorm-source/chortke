<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class RateSeoTaskJob
{
    private \App\Services\Seo\AdsSeoService $adsService;
    private \App\Services\Interaction\RatingService $ratingService;
    public function __construct(
        \App\Services\Seo\AdsSeoService $adsService,
        \App\Services\Interaction\RatingService $ratingService
    ) {        $this->adsService = $adsService;
        $this->ratingService = $ratingService;
}

    public function handle(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        $ad = $this->adsService->getAd($adId);
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
