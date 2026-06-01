<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Jobs\Seo\StartSeoTaskJob;
use App\Jobs\Seo\CompleteSeoTaskJob;
use App\Jobs\Seo\ProcessSeoTaskAsyncJob;
use App\Jobs\Seo\CancelSeoTaskJob;
use App\Jobs\Seo\ReportSeoTaskJob;
use App\Jobs\Seo\RateSeoTaskJob;

class SeoService
{
    private StartSeoTaskJob $startJob;
    private CompleteSeoTaskJob $completeJob;
    private ProcessSeoTaskAsyncJob $processAsyncJob;
    private CancelSeoTaskJob $cancelJob;
    private ReportSeoTaskJob $reportJob;
    private RateSeoTaskJob $rateJob;

    public function __construct(
        StartSeoTaskJob $startJob,
        CompleteSeoTaskJob $completeJob,
        ProcessSeoTaskAsyncJob $processAsyncJob,
        CancelSeoTaskJob $cancelJob,
        ReportSeoTaskJob $reportJob,
        RateSeoTaskJob $rateJob
    ) {
        $this->startJob = $startJob;
        $this->completeJob = $completeJob;
        $this->processAsyncJob = $processAsyncJob;
        $this->cancelJob = $cancelJob;
        $this->reportJob = $reportJob;
        $this->rateJob = $rateJob;
    }

    public function startTask(int $adId, int $userId): array
    {
        return $this->startJob->handle($adId, $userId);
    }

    public function completeTask(int $executionId, int $userId, array $engagementData): array
    {
        return $this->completeJob->handle($executionId, $userId, $engagementData);
    }

    public function processTaskAsync(int $executionId, int $userId, int $adId, array $engagementData): array
    {
        return $this->processAsyncJob->handle($executionId, $userId, $adId, $engagementData);
    }

    public function cancelTask(int $executionId, int $userId): array
    {
        return $this->cancelJob->handle($executionId, $userId);
    }

    public function reportTask(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        return $this->reportJob->handle($reporterId, $adId, $reason, $description);
    }

    public function rateTask(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        return $this->rateJob->handle($raterId, $adId, $stars, $comment);
    }

}
