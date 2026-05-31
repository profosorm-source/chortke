<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class NewTaskAvailableNotificationJob implements JobInterface
{
    public function handle(int $userId, string $taskTitle): ?int
    {

        return $this->sendFromTemplate($userId, 'task', [
            'task_title' => $taskTitle,
        ], Notification::PRIORITY_NORMAL, url('/tasks'), 'مشاهده تسک‌ها', 'task_available');
    
    }
}
