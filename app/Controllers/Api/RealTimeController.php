<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\WebSocketService;

/**
 * RealTimeController - Real-time messaging API endpoints
 */
class RealTimeController extends BaseApiController
{
    private WebSocketService $realTime;

    public function __construct(WebSocketService $realTime, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->realTime = $realTime;
    }

    /**
     * Long Polling endpoint
     */
    public function poll(): void
    {
        try {
            $userId = $this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
            }

            $lastMessageId = (int)($this->request->post('last_message_id') ?? 0);
            $timeout = min((int)($this->request->post('timeout') ?? 60), 60);

            // ✅ Long poll with timeout
            $messages = $this->realTime->longPoll($userId, $lastMessageId, $timeout);

            $this->success([
                'messages' => $messages,
                'count'    => count($messages)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.poll_failed', ['error' => $e->getMessage()]);
            $this->error('Poll failed', 500);
        }
    }

    /**
     * Join a real-time room
     */
    public function joinRoom(): void
    {
        try {
            $userId = $this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
            }

            $room = trim((string)$this->request->post('room'));
            if (empty($room)) {
                $this->error('Room name required', 400);
            }

            // ✅ Validate room format
            if (!preg_match('/^[a-z_]:[0-9]+$/', $room)) {
                $this->error('Invalid room format', 400);
            }

            $this->realTime->joinRoom($userId, $room);

            $this->logger->info('real_time.room_joined', [
                'user_id' => $userId,
                'room'    => $room
            ]);

            $this->success([
                'room' => $room,
                'msg'  => 'Subscribed to room'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.join_room_failed', ['error' => $e->getMessage()]);
            $this->error('Join failed', 500);
        }
    }

    /**
     * Leave a real-time room
     */
    public function leaveRoom(): void
    {
        try {
            $userId = $this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
            }

            $room = trim((string)$this->request->post('room'));
            if (empty($room)) {
                $this->error('Room name required', 400);
            }

            $this->realTime->leaveRoom($userId, $room);

            $this->logger->info('real_time.room_left', [
                'user_id' => $userId,
                'room'    => $room
            ]);

            $this->success([
                'room' => $room,
                'msg'  => 'Unsubscribed from room'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.leave_room_failed', ['error' => $e->getMessage()]);
            $this->error('Leave failed', 500);
        }
    }

    /**
     * Get members in a room
     */
    public function getRoomMembers(): void
    {
        try {
            $room = trim((string)$this->request->param('room'));
            if (empty($room)) {
                $this->error('Room name required', 400);
            }

            $members = $this->realTime->getRoomMembers($room);

            $this->success([
                'room'    => $room,
                'members' => $members,
                'count'   => count($members)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.get_members_failed', ['error' => $e->getMessage()]);
            $this->error('Get members failed', 500);
        }
    }

    /**
     * Get all online users count
     */
    public function getOnlineUsers(): void
    {
        try {
            $onlineCount = $this->realTime->getOnlineCount();
            $this->success(['count' => $onlineCount]);
        } catch (\Exception $e) {
            $this->logger->error('real_time.get_online_failed', ['error' => $e->getMessage()]);
            $this->error('Get online count failed', 500);
        }
    }

    /**
     * Get online users in a specific room
     */
    public function getOnlineInRoom(): void
    {
        try {
            $room = trim((string)$this->request->param('room'));
            if (empty($room)) {
                $this->error('Room name required', 400);
            }

            $onlineUsers = $this->realTime->getOnlineInRoom($room);

            $this->success([
                'room'  => $room,
                'users' => $onlineUsers,
                'count' => count($onlineUsers)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.get_online_in_room_failed', ['error' => $e->getMessage()]);
            $this->error('Get online in room failed', 500);
        }
    }

    /**
     * Get real-time system stats
     */
    public function getStats(): void
    {
        try {
            $stats = $this->realTime->getStats();
            $this->success(['stats' => $stats]);
        } catch (\Exception $e) {
            $this->logger->error('real_time.get_stats_failed', ['error' => $e->getMessage()]);
            $this->error('Get stats failed', 500);
        }
    }
}
