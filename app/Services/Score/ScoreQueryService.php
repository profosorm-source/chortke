<?php

declare(strict_types=1);

namespace App\Services\Score;

use App\Models\Score as ScoreModel;
use App\Enums\ScoreDomain;

/**
 * ScoreQueryService (Read Model / Query Side)
 * 
 * Handles ONLY the reading of scores from the projection table (user_scores) or cache.
 * Now uses Redis Hash for O(1) performance and atomic updates.
 */
class ScoreQueryService
{
    private \Core\Cache $cache;
    private \Core\Database $db;
    private ScoreModel $scoreModel;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        ScoreModel $scoreModel
    ) {        $this->cache = $cache;
        $this->db = $db;
        $this->scoreModel = $scoreModel;

            }

    /**
     * Reads score utilizing Redis Hash cache and projection DB.
     */
    public function getScore(string $entityType, int $entityId, string $domain): float
    {
        $domain = ScoreDomain::normalize($domain);
        $cacheKey = "score:{$entityType}:{$entityId}";

        $projectionScore = null;
        try {
            $redis = $this->cache->redis();
            if ($redis) {
                // Try to get from Redis Hash
                $cached = $redis->hGet($cacheKey, $domain);
                if ($cached !== false) {
                    return (float)$cached;
                }
            }
        } catch (\Throwable $e) {}

        if ($projectionScore === null) {
            try {
                if ($entityType === 'user') {
                    $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = ? LIMIT 1");
                    $stmt->execute([$entityId, $domain]);
                    $value = $stmt->fetchColumn();
                    $projectionScore = $value !== false ? (float)$value : $this->scoreModel->getDomainScore($entityId, $domain);
                } else {
                    $projectionScore = $this->scoreModel->getTotal($entityId, $entityType, $domain);
                }

                // Hydrate the Redis Hash
                if (isset($redis) && $redis) {
                    $redis->hSet($cacheKey, $domain, $projectionScore);
                    $redis->expire($cacheKey, 3600);
                }
            } catch (\Throwable $e) {
                $projectionScore = 0.0;
            }
        }

        return $projectionScore;
    }
}
