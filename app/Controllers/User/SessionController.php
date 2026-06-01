<?php

namespace App\Controllers\User;

use App\Services\Auth\SessionService;
use App\Controllers\User\BaseUserController;

class SessionController extends BaseUserController
{
    private SessionService $sessionService;

    public function __construct(
        \App\Services\Auth\SessionService $sessionService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->sessionService = $sessionService;
    }

    /**
     * صفحه نشست‌های فعال
     */
    public function index(): void
    {
        $userId = user_id();
        $sessions = $this->sessionService->getActiveSessions($userId);

        view('user.sessions.index', [
            'sessions' => $sessions,
            'currentSessionId' => \session_id()
        ]);
    }

    /**
     * حذف نشست (Action-based → JSON)
     */
    public function terminate(int $id): void
    {
        $this->validateCsrf();

        $userId = user_id();

        $result = $this->sessionService->terminateSession($id, $userId);

        $this->response->json([
            'success' => $result['success'],
            'message' => $result['message']
        ], $result['success'] ? 200 : 400);
    }
}