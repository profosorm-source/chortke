<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class NotificationRequestedEvent extends Event
{
    public int $userId;
    public string $type;
    public string $title;
    public string $message;
    public array $data;
    public ?string $actionUrl;
    public ?string $actionText;
    public string $priority;

    public function __construct(
        int $userId,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal'
    ) {
        parent::__construct();
        $this->userId = $userId;
        $this->type = $type;
        $this->title = $title;
        $this->message = $message;
        $this->data = $data;
        $this->actionUrl = $actionUrl;
        $this->actionText = $actionText;
        $this->priority = $priority;
    }
}
