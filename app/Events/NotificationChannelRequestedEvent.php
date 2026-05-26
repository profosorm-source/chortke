<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class NotificationChannelRequestedEvent extends Event
{
    public string $channel;
    public int $userId;
    public string $title;
    public string $message;
    public array $data;
    public ?string $imageUrl;
    public ?string $actionUrl;
    public ?string $actionText;
    public string $priority;

    public function __construct(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = [],
        ?string $imageUrl = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal'
    ) {
        parent::__construct();
        $this->channel = strtolower(trim($channel));
        $this->userId = $userId;
        $this->title = $title;
        $this->message = $message;
        $this->data = $data ?? [];
        $this->imageUrl = $imageUrl;
        $this->actionUrl = $actionUrl;
        $this->actionText = $actionText;
        $this->priority = $priority;
    }
}
