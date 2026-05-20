<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\InfluencerService;
use App\Services\SocialTask\SocialTaskService;
use App\Services\VitrineService;

/** Explicit adapter for module search reads. */
final class ModuleSearchGateway
{
    public function __construct(
        private SocialTaskService $socialTaskService,
        private InfluencerService $influencerService,
        private VitrineService $vitrineService
    ) {}

    public function searchSocialTasks(array $filters, int $limit, int $offset): array
    {
        return $this->socialTaskService->searchSocialTasks($filters, $limit, $offset);
    }

    public function searchInfluencers(array $filters, int $limit, int $offset): array
    {
        return $this->influencerService->searchInfluencers($filters, $limit, $offset);
    }

    public function searchVitrine(array $filters, int $limit, int $offset): array
    {
        return $this->vitrineService->searchVitrine($filters, $limit, $offset);
    }
}
