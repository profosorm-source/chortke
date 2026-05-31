<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class RenderTemplateNotificationJob implements JobInterface
{
    public function handle(string $templateKey, array $vars = []): array
    {

        return $this->templateService->renderTemplate($templateKey, $vars);
    
    }
}
