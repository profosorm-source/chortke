<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class GetUsersBySegmentNotificationJob implements JobInterface
{
    public function handle(string $segment, array $filters = []): array
    {

        return $this->model->getUsersBySegment($segment, $filters);
    
    }
}
