<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class WithdrawalEvent extends Event
{
    public function __construct(array $data = [])
    {
        $payload = array_merge([
            'action' => '',
            'user_id' => null,
            'withdrawal_id' => null,
            'transaction_id' => null,
            'amount' => 0,
            'currency' => 'irt',
            'status' => null,
            'reason' => null,
            'admin_id' => null,
            'metadata' => [],
        ], $data);

        parent::__construct($payload);
    }
}
