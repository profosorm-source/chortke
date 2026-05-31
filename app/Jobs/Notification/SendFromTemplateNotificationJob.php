<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class SendFromTemplateNotificationJob implements JobInterface
{
    public function handle(
        int    $userId,
        string $templateKey,
        array  $vars       = [],
        string $priority   = Notification::PRIORITY_NORMAL,
        ?string $actionUrl = null,
        ?string $actionText= null,
        ?string $groupKey  = null,
        ?string $scheduledAt = null
    ): ?int
    {

        $rendered = $this->templateService->renderTemplate($templateKey, $vars);
        $prefix = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', explode('_', $templateKey)[0] ?? ''));
        $allowedTypes = [
            'deposit' => Notification::TYPE_DEPOSIT,
            'withdrawal' => Notification::TYPE_WITHDRAWAL,
            'task' => Notification::TYPE_TASK,
            'kyc' => Notification::TYPE_KYC,
            'lottery' => Notification::TYPE_LOTTERY,
            'referral' => Notification::TYPE_REFERRAL,
            'security' => Notification::TYPE_SECURITY,
            'investment' => Notification::TYPE_INVESTMENT,
            'info' => Notification::TYPE_INFO,
            'marketing' => Notification::TYPE_MARKETING,
        ];

        $type = $allowedTypes[$prefix] ?? Notification::TYPE_SYSTEM;

        return $this->send(
            $userId,
            $type,
            $rendered['title'],
            $rendered['message'],
            $vars,
            $actionUrl,
            $actionText,
            $priority,
            null,
            null,
            $groupKey ?? $templateKey,
            $scheduledAt
        );
    
    }
}
