<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Services\Auth\AuthService;

class ProcessLoginJob
{
    private AuthService $authService;
    public function __construct(
        AuthService $authService
    ) {        $this->authService = $authService;
}

    public function handle(string $identifier, string $password, bool $remember = false): array
    {
        return $this->authService->login($identifier, $password, $remember);
    }
}
