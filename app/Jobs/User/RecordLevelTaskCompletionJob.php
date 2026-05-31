<?php

declare(strict_types=1);

namespace App\Jobs\User;

class RecordLevelTaskCompletionJob
{
    public function __construct(
        
    ) {}

    public function handle(int $userId, float $earnedAmount, string $currency = 'irt'): void
    {
        if (!$this->isEnabled()) return;

        // In Gamification Architecture, tasks and earnings don't directly write to the users table
        // We just record the daily activity so the user might get upgraded if their total score meets the threshold.
        // Task completions now generate Gamification Score via Gamification\ScoreService, so the Score table acts as the ledger.


        $this->recordDailyActivity($userId);
    }
}
