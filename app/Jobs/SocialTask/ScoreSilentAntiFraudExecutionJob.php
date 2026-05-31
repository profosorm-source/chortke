<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

class ScoreSilentAntiFraudExecutionJob
{
    public function __construct(
        
    ) {}

    public function handle(object $exec, array $payload): array
    {
        return $this->scoringService->calculate([
            'active_time' => (int)($payload['active_time'] ?? 0),
            'expected_time' => (int)($exec->expected_time ?? 60),
            'interactions' => (array)($payload['interactions'] ?? []),
            'behavior_signals' => (array)($payload['behavior_signals'] ?? []),
            'trust_modifier' => $this->getTrustModifier((int)$exec->executor_id),
        ]);
    }
}
