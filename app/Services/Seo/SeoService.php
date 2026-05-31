<?php

declare(strict_types=1);

namespace App\Services\Seo;

class SeoService extends \App\Services\BaseService
{
    public function __construct() {}

    public function startTask(int $adId, int $userId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Seo\StartSeoTaskJob::class);
        return $job->handle($adId, $userId);
    }

    public function completeTask(int $executionId, int $userId, array $engagementData): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Seo\CompleteSeoTaskJob::class);
        return $job->handle($executionId, $userId, $engagementData);
    }

    public function processTaskAsync(int $executionId, int $userId, int $adId, array $engagementData): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Seo\ProcessSeoTaskAsyncJob::class);
        return $job->handle($executionId, $userId, $adId, $engagementData);
    }

    public function cancelTask(int $executionId, int $userId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Seo\CancelSeoTaskJob::class);
        return $job->handle($executionId, $userId);
    }

    public function reportTask(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Seo\ReportSeoTaskJob::class);
        return $job->handle($reporterId, $adId, $reason, $description);
    }

    public function rateTask(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Seo\RateSeoTaskJob::class);
        return $job->handle($raterId, $adId, $stars, $comment);
    }

}
