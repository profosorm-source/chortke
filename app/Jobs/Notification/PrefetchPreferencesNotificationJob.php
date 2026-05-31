<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class PrefetchPreferencesNotificationJob implements JobInterface
{
    public function handle(array $userIds): void
    {

        $this->policyService->prefetchPreferences($userIds);
    
    }
}
