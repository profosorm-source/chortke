<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class InfluencerReputation extends Model
{
    protected static string $table = 'influencer_reputation_events';

    private InfluencerModel $influencerModel;

    public function __construct(\Core\Database $db, InfluencerModel $influencerModel)
    {
        parent::__construct($db);
        $this->influencerModel = $influencerModel;
    }

    public function addEvent(array $d): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO influencer_reputation_events
                (profile_id, user_id, order_id, event_type, points, note, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            return $stmt->execute([
                $d['profile_id'],
                $d['user_id'],
                $d['order_id'] ?? null,
                $d['event_type'],
                $d['points'],
                $d['note'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getProfileStats(int $profileId): object
    {
        return $this->influencerModel->getReputationStats($profileId);
    }
}
