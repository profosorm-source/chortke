<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

class ApproveSocialTaskExecutionJob
{
    private \App\Models\CryptoDeposit $model;
    public function __construct(
        \App\Models\CryptoDeposit $model
    ) {        $this->model = $model;
}

    public function handle(int $advertiserId, int $executionId): array
    {
        $exec = $this->model->getExecutionById($executionId);
        if (!$exec || (int)$exec->user_id !== $advertiserId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->model->updateExecutionStatus($executionId, 'approved');
        return ['success' => true, 'message' => 'اجرا تأیید شد'];
    }
}
