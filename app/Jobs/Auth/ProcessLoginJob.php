<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class ProcessLoginJob
{
    public function __construct(
        
    ) {}

    public function handle(string $identifier, string $password, bool $remember = false): array
    {
        return $this->performLogin($identifier, $password, $remember, false);
    }
}
