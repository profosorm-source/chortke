<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ScoreDeltaAppendedEvent;
use App\Events\ScoreUpdatedEvent;
use Core\Database;
use Core\Cache;
use Core\EventDispatcher;
use App\Services\Cache\CacheInvalidationService;

/**
 * ScoreProjectionListener
 * 
 * Asynchronously projects score deltas to the user_scores read model 
 * and handles cache invalidation.
 */
class ScoreProjectionListener
{
    private Database $db;
    private Cache $cache;
    private EventDispatcher $eventDispatcher;
    private CacheInvalidationService $cacheInvalidation;
    public function __construct(
        Database $db,
        Cache $cache,
        EventDispatcher $eventDispatcher,
        CacheInvalidationService $cacheInvalidation
    ) {        $this->db = $db;
        $this->cache = $cache;
        $this->eventDispatcher = $eventDispatcher;
        $this->cacheInvalidation = $cacheInvalidation;
}

    public function handle(ScoreDeltaAppendedEvent $event): void
    {
        // 1. Update Read Model (user_scores table)
        if ($event->entityType === 'user') {
            $stmt = $this->db->prepare("
                INSERT INTO user_scores (user_id, domain, score, updated_at) 
                VALUES (?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                score = score + VALUES(score), updated_at = NOW()
            ");
            $stmt->execute([$event->entityId, $event->domain, $event->delta]);

            // Fetch new total score to dispatch standard ScoreUpdatedEvent for gamification triggers
            try {
                $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = ? LIMIT 1");
                $stmt->execute([$event->entityId, $event->domain]);
                $val = $stmt->fetchColumn();
                $newScore = $val !== false ? (float)$val : null;
                $oldScore = $newScore !== null ? $newScore - $event->delta : 0.0;
                
                if ($newScore !== null) {
                    $this->eventDispatcher->dispatchAsync(ScoreUpdatedEvent::class, new ScoreUpdatedEvent(
                        $event->entityId,
                        (float)$oldScore,
                        (float)$newScore,
                        $event->source
                    ));
                }
            } catch (\Throwable $e) {}
        }

        // 2. Invalidate Caches
        $this->invalidateScoreCache($event->entityType, $event->entityId, $event->domain);
    }

    private function invalidateScoreCache(string $entityType, int $entityId, string $domain): void
    {
        $keys = [
            "score:{$entityType}:{$entityId}:{$domain}",
            "temp_{$domain}_score:{$entityId}"
        ];

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }
        
        // Ensure any newly recreated temp keys have an expiration to prevent memory leaks for inactive users
        try {
            $redis = $this->cache->redis();
            if ($redis) {
                // EXPIRE the hash keys we might have touched
                $redis->expire("score:{$entityType}:{$entityId}:{$domain}", 3600 * 24); // 24 hours
                $redis->expire("temp_{$domain}_score:{$entityId}", 3600 * 24); // 24 hours
            }
        } catch (\Throwable $e) {}

        $this->cacheInvalidation->invalidateScore($entityId, $domain);
    }
}
