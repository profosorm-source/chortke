<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class CancelSeoTaskJob
{
    private \App\Services\Seo\AdsSeoService $adsService;
    public function __construct(
        \App\Services\Seo\AdsSeoService $adsService
    ) {        $this->adsService = $adsService;
}

public function handle(int $executionId, int $userId): array
    {


        $this->adsService->rejectExecution($executionId, 'لغو شده توسط کاربر');

        return ['success' => true, 'message' => 'تسک لغو شد'];
    }
}
