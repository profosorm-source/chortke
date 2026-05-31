<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class ResetPasswordJob
{
    public function __construct(
        
    ) {}

    public function handle(string $token, string $newPassword, ?string $email = null): array
    {
        return $this->passwordService->resetPassword($token, $newPassword, $email);
    }
}
