<?php

declare(strict_types=1);

namespace App\Services;

use Core\Redis;
use Core\Database;

use App\Contracts\LoggerInterface;
/**
 * WebSocketService - WebSocket + Long Polling infrastructure
 * 
 * Dual implementation:
 * 1. WebSocket: Real native connection (if available)
 * 2. Long Polling: Fallback for all clients
 * 
 * Features:
 * - Room-based messaging (subscriptions)
 * - Presence tracking (online/offline)
 * - Message queue persistence
 * - Event-driven notifications
 * - Connection fallback
 */
class WebSocketService
extends \App\Services\BaseService
{
    private Redis $redis;
    private Database $db;
    private const PRESENCE_TTL = 60;              // 60 seconds
    private const MESSAGE_RETENTION = 3600;       // 1 hour
    private const POLL_TIMEOUT = 60;              // 60 seconds (increased from 25)
    private const MAX_MESSAGES_PER_POLL = 50;     // Max messages to return
    private const ROOM_PREFIX = 'room:';
    private const PRESENCE_PREFIX = 'presence:';
    private const QUEUE_PREFIX = 'queue:';
    private const DELAYED_QUEUE_PREFIX = 'delayed:';
    private const BATCH_SIZE = 10;                // Batch size for message delivery
    private const DELIVERY_DELAY = 30;            // 30 seconds delay before delivery
    private const POLL_INTERVAL = 2000000;        // 2 seconds (increased from 0.5s)

    public function __construct(Redis $redis, Database $db, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->redis = $redis;
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Room Management
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create or join a room
     * 
     * Room naming conventions:
     * - "user:{userId}" - Personal notifications
     * - "order:{orderId}" - Order updates
     * - "task:{taskId}" - Task notifications
     * - "admin" - Admin notifications
     */
    public function joinRoom(int $userId, string $room): bool
    {
        try {
            $key = self::ROOM_PREFIX . $room . ':members';
            
            // ✅ Add user to room
            $this->redis->sAdd($key, (string)$userId);
            
            // ✅ Set room expiration to 24 hours
            $this->redis->expire($key, 86400);
            
            $this->logger->debug('websocket.join_room', ['user' => $userId, 'room' => $room]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('websocket.join_room.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Leave a room
     */
    public function leaveRoom(int $userId, string $room): bool
    {
        try {
            $key = self::ROOM_PREFIX . $room . ':members';
            $this->redis->sRem($key, (string)$userId);
            
            $this->logger->debug('websocket.leave_room', ['user' => $userId, 'room' => $room]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('websocket.leave_room.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get all users in a room
     */
    public function getRoomMembers(string $room): array
    {
        $key = self::ROOM_PREFIX . $room . ':members';
        $members = $this->redis->sMembers($key) ?? [];
        return array_map('intval', $members);
    }

    /**
     * Get user's rooms
     */
    public function getUserRooms(int $userId): array
    {
        $pattern = self::ROOM_PREFIX . '*:members';
        $keys = [];
        $cursor = '0';
        do {
            $result = $this->redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 100);
            $cursor = $result[0];
            $keys = array_merge($keys, $result[1]);
        } while ($cursor !== '0');

        $rooms = [];

        foreach ($keys as $key) {
            if ($this->redis->sIsMember($key, (string)$userId)) {
                $room = str_replace([self::ROOM_PREFIX, ':members'], '', $key);
                $rooms[] = $room;
            }
        }

        return $rooms;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Message Publishing
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Publish message to room (with batching and delay)
     */
    public function publishToRoom(string $room, array $message, ?string $sender = null): bool
    {
        try {
            // ✅ Add message metadata
            $msg = array_merge($message, [
                'id' => uniqid('msg_'),
                'room' => $room,
                'sender' => $sender,
                'timestamp' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + self::MESSAGE_RETENTION)
            ]);

            // ✅ Save to database (persistent queue)
            $this->db->query(
                "INSERT INTO realtime_messages (room, message_type, payload, expires_at, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$room, $msg['type'] ?? 'general', json_encode($msg), $msg['expires_at']]
            );

            // ✅ Add to delayed queue with timestamp for batching
            $delayedKey = self::DELAYED_QUEUE_PREFIX . $room;
            $deliverAt = time() + self::DELIVERY_DELAY;
            $this->redis->zAdd($delayedKey, $deliverAt, json_encode($msg));
            $this->redis->expire($delayedKey, self::MESSAGE_RETENTION);

            // ✅ Process any ready messages for immediate delivery (batching)
            $this->processDelayedMessages($room);

            $this->logger->debug('websocket.publish_delayed', ['room' => $room, 'type' => $msg['type'] ?? 'general', 'delay' => self::DELIVERY_DELAY]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('websocket.publish.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Process delayed messages that are ready for delivery (batching)
     */
    private function processDelayedMessages(string $room): void
    {
        $delayedKey = self::DELAYED_QUEUE_PREFIX . $room;
        $queueKey = self::QUEUE_PREFIX . $room;
        $now = time();

        // ✅ Get messages ready for delivery (up to batch size)
        $readyMessages = $this->redis->zRangeByScore($delayedKey, 0, $now, ['limit' => [0, self::BATCH_SIZE]]);

        if (!empty($readyMessages)) {
            // ✅ Remove from delayed queue (only the fetched messages)
            foreach ($readyMessages as $msgJson) {
                $this->redis->zRem($delayedKey, $msgJson);
            }

            // ✅ Add to regular queue for polling
            foreach ($readyMessages as $msgJson) {
                $this->redis->lPush($queueKey, $msgJson);
            }

            // ✅ Publish batch via Redis (optional - for WebSocket clients)
            $this->redis->publish($room, json_encode([
                'type' => 'batch_delivery',
                'count' => count($readyMessages),
                'timestamp' => date('Y-m-d H:i:s')
            ]));

            // ✅ Limit queue size
            $this->redis->lTrim($queueKey, 0, 1000);
            $this->redis->expire($queueKey, self::MESSAGE_RETENTION);

            $this->logger->debug('websocket.batch_delivered', ['room' => $room, 'count' => count($readyMessages)]);
        }
    }

    /**
     * Broadcast to multiple users
     */
    public function broadcastToUsers(array $userIds, array $message): int
    {
        $count = 0;
        foreach ($userIds as $userId) {
            if ($this->sendToUser($userId, $message)) {
                $count++;
            }
        }
        return $count;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Long Polling (Fallback)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Long polling endpoint for clients (optimized with reduced frequency)
     * 
     * Returns messages since lastMessageId with up to 60 second timeout
     */
    public function longPoll(int $userId, ?string $lastMessageId = null, int $timeout = self::POLL_TIMEOUT): array
    {
        $messages = [];
        $startTime = time();
        $endTime = $startTime + $timeout;

        // ✅ Get user's subscribed rooms
        $rooms = $this->getUserRooms($userId);
        $rooms[] = "user:{$userId}"; // Always listen to personal room

        // ✅ Process any delayed messages first
        foreach ($rooms as $room) {
            $this->processDelayedMessages($room);
        }

        // ✅ Poll with reduced frequency (every 2 seconds instead of 0.5s)
        while (time() < $endTime) {
            // ✅ Check for messages in all rooms
            foreach ($rooms as $room) {
                $roomMessages = $this->getQueueMessages($room, $lastMessageId);
                if (!empty($roomMessages)) {
                    $messages = array_merge($messages, $roomMessages);
                }
            }

            // ✅ If we have messages, return immediately
            if (!empty($messages)) {
                return [
                    'ok' => true,
                    'messages' => array_slice($messages, 0, self::MAX_MESSAGES_PER_POLL),
                    'count' => count($messages)
                ];
            }

            // ✅ Sleep for longer interval (2 seconds)
            usleep(self::POLL_INTERVAL); // 2 seconds
        }

        // ✅ Timeout - return empty
        return [
            'ok' => true,
            'messages' => [],
            'count' => 0,
            'timeout' => true
        ];
    }

    /**
     * Get messages from queue since lastMessageId
     */
    private function getQueueMessages(string $room, ?string $lastMessageId = null): array
    {
        $queueKey = self::QUEUE_PREFIX . $room;
        $messages = [];

        // ✅ Get all messages from queue
        $allMessages = $this->redis->lRange($queueKey, 0, self::MAX_MESSAGES_PER_POLL) ?? [];

        foreach ($allMessages as $msgJson) {
            $msg = json_decode($msgJson, true);
            
            // ✅ Filter messages after lastMessageId
            if ($lastMessageId && $msg['id'] === $lastMessageId) {
                break;
            }

            if (isset($msg['expires_at']) && strtotime($msg['expires_at']) > time()) {
                $messages[] = $msg;
            }
        }

        return array_reverse($messages); // Oldest first
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Presence Tracking
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Update user presence (online)
     */
    public function updatePresence(int $userId): void
    {
        $key = self::PRESENCE_PREFIX . $userId;
        $this->redis->setex($key, self::PRESENCE_TTL, json_encode([
            'user_id' => $userId,
            'online_at' => date('Y-m-d H:i:s'),
            'status' => 'online'
        ]));
    }

    /**
     * Check if user is online
     */
    public function isOnline(int $userId): bool
    {
        return $this->redis->exists(self::PRESENCE_PREFIX . $userId) === 1;
    }

    /**
     * Get online users in room
     */
    public function getOnlineInRoom(string $room): array
    {
        $members = $this->getRoomMembers($room);
        $online = [];

        foreach ($members as $userId) {
            if ($this->isOnline($userId)) {
                $online[] = $userId;
            }
        }

        return $online;
    }

    /**
     * Get online count
     */
    public function getOnlineCount(): int
    {
        $pattern = self::PRESENCE_PREFIX . '*';
        // ✅ Using scanKeys() instead of keys() for performance
        $keys = $this->redis->scanKeys($pattern);
        return count($keys);
    }

    /**
     * Mark user as offline
     */
    public function markOffline(int $userId): void
    {
        $this->redis->del(self::PRESENCE_PREFIX . $userId);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Notification Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Notify task execution started
     */
    public function notifyExecutionStarted(int $taskId, int $executorId): void
    {
        $room = "task:{$taskId}";
        $this->publishToRoom($room, [
            'type' => 'execution_started',
            'task_id' => $taskId,
            'executor_id' => $executorId,
            'message' => 'اجرا شروع شد'
        ]);
    }

    /**
     * Notify order status changed
     */
    public function notifyOrderStatusChanged(int $orderId, string $status, array $details = []): void
    {
        $room = "order:{$orderId}";
        $this->publishToRoom($room, array_merge([
            'type' => 'order_status_changed',
            'order_id' => $orderId,
            'status' => $status,
            'message' => "وضعیت سفارش: {$status}"
        ], $details));
    }

    /**
     * Notify listing approved
     */
    public function notifyListingApproved(int $listingId, int $sellerId): void
    {
        $this->sendToUser($sellerId, [
            'type' => 'listing_approved',
            'listing_id' => $listingId,
            'message' => 'فهرست شما تایید شد'
        ]);
    }

    /**
     * Notify dispute opened
     */
    public function notifyDisputeOpened(int $disputeId, array $parties): void
    {
        foreach ($parties as $userId) {
            $this->sendToUser($userId, [
                'type' => 'dispute_opened',
                'dispute_id' => $disputeId,
                'message' => 'یک درخواست نزاع باز شد'
            ]);
        }
    }

    /**
     * Notify dispute resolved
     */
    public function notifyDisputeResolved(int $disputeId, string $verdict): void
    {
        $room = "dispute:{$disputeId}";
        $this->publishToRoom($room, [
            'type' => 'dispute_resolved',
            'dispute_id' => $disputeId,
            'verdict' => $verdict,
            'message' => "نزاع تصمیم گیری شد: {$verdict}"
        ]);
    }

    /**
     * Notify payment received
     */
    public function notifyPaymentReceived(int $userId, float $amount): void
    {
        $this->sendToUser($userId, [
            'type' => 'payment_received',
            'amount' => $amount,
            'message' => "پرداخت دریافت شد: {$amount}"
        ]);
    }

    /**
     * Notify verification status
     */
    public function notifyVerificationStatus(int $userId, string $status): void
    {
        $this->sendToUser($userId, [
            'type' => 'verification_status',
            'status' => $status,
            'message' => "وضعیت تایید: {$status}"
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Maintenance
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Batch process delayed messages for all rooms (call this periodically)
     */
    public function processAllDelayedMessages(): int
    {
        $totalProcessed = 0;
        $pattern = self::DELAYED_QUEUE_PREFIX . '*';
        $delayedKeys = $this->redis->keys($pattern) ?? [];

        foreach ($delayedKeys as $delayedKey) {
            $room = str_replace(self::DELAYED_QUEUE_PREFIX, '', $delayedKey);
            $this->processDelayedMessages($room);
            $totalProcessed++;
        }

        if ($totalProcessed > 0) {
            $this->logger->info('websocket.batch_processing', ['rooms_processed' => $totalProcessed]);
        }

        return $totalProcessed;
    }

    /**
     * Get server stats
     */
    public function getStats(): array
    {
        $delayedPattern = self::DELAYED_QUEUE_PREFIX . '*';
        $delayedKeys = [];
        $cursor = '0';
        do {
            $result = $this->redis->scan($cursor, 'MATCH', $delayedPattern, 'COUNT', 100);
            $cursor = $result[0];
            $delayedKeys = array_merge($delayedKeys, $result[1]);
        } while ($cursor !== '0');

        $delayedCount = 0;
        foreach ($delayedKeys as $key) {
            $delayedCount += $this->redis->zCard($key);
        }

        $roomPattern = self::ROOM_PREFIX . '*:members';
        $roomKeys = [];
        $cursor = '0';
        do {
            $result = $this->redis->scan($cursor, 'MATCH', $roomPattern, 'COUNT', 100);
            $cursor = $result[0];
            $roomKeys = array_merge($roomKeys, $result[1]);
        } while ($cursor !== '0');

        return [
            'online_users' => $this->getOnlineCount(),
            'pending_messages' => $this->db->query(
                "SELECT COUNT(*) as count FROM realtime_messages"
            )->fetch()?->count ?? 0,
            'delayed_messages' => $delayedCount,
            'rooms' => count($roomKeys),
            'batch_size' => self::BATCH_SIZE,
            'delivery_delay' => self::DELIVERY_DELAY,
            'poll_timeout' => self::POLL_TIMEOUT
        ];
    }
}

