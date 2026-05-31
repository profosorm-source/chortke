<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class GetAllTemplatesWithVariablesNotificationJob implements JobInterface
{
    public function handle(): array
    {

        return $this->templateService->getAllTemplatesWithVariables();
    
    }
}
