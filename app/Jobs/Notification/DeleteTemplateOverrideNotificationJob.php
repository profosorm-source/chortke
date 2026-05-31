<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class DeleteTemplateOverrideNotificationJob implements JobInterface
{
    public function handle(string $key): bool
    {

        return $this->templateService->deleteTemplateOverride($key);
    
    }
}
