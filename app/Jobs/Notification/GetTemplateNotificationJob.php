<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class GetTemplateNotificationJob implements JobInterface
{
    public function handle(string $templateKey): array
    {

        return $this->templateService->getTemplate($templateKey);
    
    }
}
