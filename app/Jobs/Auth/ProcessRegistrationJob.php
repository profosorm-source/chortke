<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class ProcessRegistrationJob
{
    public function __construct(
        
    ) {}

    public function handle(array $data): array
    {
        $result = $this->userService->register($data);
        if (!$result) {
            return ['success' => false, 'message' => '??????? ?? ???? ????? ??.'];
        }

        $userId = $result['id'];
        $plainToken = $result['plain_token'];

        $this->eventDispatcher->dispatchAsync(
            'auth.register', 
            new UserRegisteredEvent($userId, $data['email'] ?? '', client_ip(), $plainToken)
        );
        
        return ['success' => true, 'message' => '??????? ?? ?????? ????? ??.'];
    }
}
