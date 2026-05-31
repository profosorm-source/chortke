<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

class RejectSocialTaskExecutionJob
{
    public function __construct(
        private \App\Models\CryptoDeposit $model
    ) {}

    public function handle(int $advertiserId, int $executionId, string $reason): array
    {
        if (empty(trim($reason))) return ['success' => false, 'message' => 'دلیل رد الزامی است'];

        $exec = $this->model->getExecutionById($executionId);
        if (!$exec || (int)$exec->user_id !== $advertiserId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->model->updateExecutionStatus($executionId, 'rejected', ['reject_reason' => $reason]);
        
        $this->eventDispatcher->dispatch('social_task.rejected', [
            'execution_id' => $executionId,
            'executor_id' => $exec->executor_id,
            'reason' => $reason
        ]);

        return ['success' => true, 'message' => 'اجرا رد شد'];
    }
}
