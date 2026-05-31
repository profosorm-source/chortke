<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class CancelSeoTaskJob
{
    public function __construct(
        private \App\Repositories\SeoRepository $repository
    ) {}

public function handle(int $executionId, int $userId): array
    {


        $this->repository->rejectExecution($executionId, 'لغو شده توسط کاربر');

        return ['success' => true, 'message' => 'تسک لغو شد'];
    }
}
