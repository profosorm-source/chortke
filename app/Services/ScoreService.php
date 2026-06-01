<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Score\ScoreCommandService;
use App\Services\Score\ScoreQueryService;

/**
 * Unified Score Service (Facade)
 * 
 * This service acts as a backward-compatible Facade for the new CQRS-based Score System.
 * It delegates write operations to ScoreCommandService and read operations to ScoreQueryService.
 */
class ScoreService
{
    private ScoreCommandService $commandService;
    private ScoreQueryService $queryService;
    public function __construct(
        ScoreCommandService $commandService,
        ScoreQueryService $queryService
    ) {        $this->commandService = $commandService;
        $this->queryService = $queryService;

            }

    /**
     * Delegates delta application to the Command service (Write Model)
     */
    public function applyDelta(string $entityType, int $entityId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        return $this->commandService->applyDelta($entityType, $entityId, $domain, $delta, $source, $meta);
    }

    /**
     * Delegates score reading to the Query service (Read Model)
     */
    public function getScore(string $entityType, int $entityId, string $domain): float
    {
        return $this->queryService->getScore($entityType, $entityId, $domain);
    }
}
