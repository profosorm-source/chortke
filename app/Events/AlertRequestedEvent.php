<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class AlertRequestedEvent extends Event
{
    public array $alert;

    public function __construct(array $alert)
    {
        parent::__construct($alert);
        $this->alert = $alert;
    }
}
