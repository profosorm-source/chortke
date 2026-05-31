<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

class SubmitSocialTaskExecutionJob
{
    public function __construct(
        
    ) {}

    public function handle(int $userId, int $executionId, array $payload = []): array
    {
        return $this->idempotencyService->execute(
            'social_task.submit',
            $userId,
            ['execution_id' => $executionId],
            fn() => $this->submitExecutionInternal($userId, $executionId, $payload)
        );
    }
}
