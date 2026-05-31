<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class SaveTemplateOverrideNotificationJob implements JobInterface
{
    public function handle(string $key, string $title, string $message): bool
    {

        return $this->templateService->saveTemplateOverride($key, $title, $message);
    
    }
}
