<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Redis;

use App\Contracts\LoggerInterface;
/**
 * RealTimeService - Real-time messaging و notifications
 * 
 * Implementations:
 * 1. Long Polling (HTTP-based, works everywhere)
 * 2. WebSocket (planned for future - Socket.io)
 * 
 * Currently: Long Polling + Redis for presence
 */
class RealTimeServiceextends \App\Services\BaseService
{
    private Database $db;
    private Redis    $redis;

    private const LONG_POLL_TIMEOUT = 25; // 25 seconds (< 30 second HTTP timeout)
    private const MESSAGE_TTL = 3600; // 1 hour
    private const PRESENCE_TTL = 60; // 1 minute

    public function __construct(Database $db, LoggerInterface $logger, Redis $redis)
    {
        parent::__construct($logger);
        $this->db     = $db;
        $this->redis  = $redis;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Long Polling API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Long Polling endpoint - wait for messages
     * Client calls this and waits for response (max 25 seconds)
     * 
     * @param int $userId User ID
     * @param int $lastMessageId Last received message ID
     * @return array Messages for user
     */
    public function longPoll(int $userId, int $lastMessageId = 0): array
    {
        $startTime = time();

        while ((time() - $startTime) < self::LONG_POLL_TIMEOUT) {
            // ✅ Check for new messages
            $messages = $this->getNewMessages($userId, $lastMessageId);
            
            if (!empty($messages)) {
                return [
                    'ok' => true,
                    'messages' => $messages,
                    'wait_time' => time() - $startTime
                ];
            }

            // ✅ Update presence
            $this->updatePresence($userId);

            // ✅ Sleep 1 second before polling again
            sleep(1);
        }

        // ✅ Timeout - return empty messages
        $this->updatePresence($userId);
        return [
            'ok' => true,
            'messages' => [],
            'wait_time' => self::LONG_POLL_TIMEOUT
        ];
    }

    /**
     * Get messages newer than $lastId
     */
    private function getNewMessages(int $userId, int $lastId = 0): array
    {
        $messages = $this->db->query(
            "SELECT * FROM realtime_messages 
             WHERE user_id = ? AND id > ? AND expires_at > NOW()
             ORDER BY created_at ASC
             LIMIT 50",
            [$userId, $lastId]
        )->fetchAll() ?? [];

        return $messages;
    }

    /**
     * Send message to user (queue for long polling)
     */
    public function sendMessage(
        int    $userId,
        string $type,
        array  $payload,
        ?int   $relatedUserId = null
    ): array {
        try {
            $this->db->query(
                "INSERT INTO realtime_messages 
                 (user_id, type, payload, related_user_id, expires_at, created_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())",
                [
                    $userId,
                    $type,
                    json_encode($payload),
                    $relatedUserId,
                    self::MESSAGE_TTL
                ]
            );

            return ['ok' => true];

        } catch (\Exception $e) {
            $this->logger->error('realtime.send_message.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Broadcast message to multiple users
     */
    public function broadcast(
        array  $userIds,
        string $type,
        array  $payload
    ): array {
        try {
            foreach ($userIds as $userId) {
                $this->sendMessage($userId, $type, $payload);
            }
            return ['ok' => true, 'recipients' => count($userIds)];
        } catch (\Exception $e) {
            $this->logger->error('realtime.broadcast.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send to all users in a room/channel
     */
    public function sendToRoom(string $room, string $type, array $payload): array
    {
        try {
            $userIds = $this->redis->smembers("room:$room");
            if (empty($userIds)) {
                return ['ok' => true, 'recipients' => 0];
            }

            return $this->broadcast($userIds, $type, $payload);
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Presence Tracking (Redis)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Update user presence
     */
    public function updatePresence(int $userId): void
    {
        $key = "user:online:$userId";
        $this->redis->setex($key, self::PRESENCE_TTL, json_encode([
            'user_id' => $userId,
            'last_seen' => time(),
            'online' => true
        ]));
    }

    /**
     * Check if user is online
     */
    public function isOnline(int $userId): bool
    {
        return $this->redis->exists("user:online:$userId") > 0;
    }

    /**
     * Get list of online users in room
     */
    public function getOnlineInRoom(string $room): array
    {
        $userIds = $this->redis->smembers("room:$room");
        $online = [];

        foreach ($userIds as $userId) {
            if ($this->isOnline((int)$userId)) {
                $online[] = $userId;
            }
        }

        return $online;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Room Management
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Join room (for notifications)
     */
    public function joinRoom(int $userId, string $room): void
    {
        $this->redis->sadd("room:$room", $userId);
    }

    /**
     * Leave room
     */
    public function leaveRoom(int $userId, string $room): void
    {
        $this->redis->srem("room:$room", $userId);
    }

    /**
     * Get all rooms user is in
     */
    public function getUserRooms(int $userId): array
    {
        $pattern = "room:*";
        $rooms = [];

        // ✅ Get all room keys
        $keys = $this->redis->keys($pattern);
        foreach ($keys as $key) {
            if ($this->redis->sismember($key, $userId)) {
                $rooms[] = str_replace('room:', '', $key);
            }
        }

        return $rooms;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Event-Driven Notifications
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * SocialTask execution started notification
     */
    public function notifyExecutionStarted(int $executionId, int $advertiserId, string $taskTitle): void
    {
        $this->sendMessage($advertiserId, 'social_task.execution_started', [
            'execution_id' => $executionId,
            'task_title' => $taskTitle,
            'message' => "یک فرد برای تسک شما اقدام کرد.",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * SocialTask execution submitted notification
     */
    public function notifyExecutionSubmitted(
        int    $executionId,
        int    $advertiserId,
        string $taskTitle
    ): void {
        $this->sendMessage($advertiserId, 'social_task.execution_submitted', [
            'execution_id' => $executionId,
            'task_title' => $taskTitle,
            'message' => "بررسی تسک انجام شد - پاسخ را تایید یا رد کنید.",
            'action_url' => "/admin/social-tasks/$executionId",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Influencer order status changed
     */
    public function notifyOrderStatusChanged(
        int    $orderId,
        int    $userId,
        string $newStatus
    ): void {
        $this->sendMessage($userId, 'influencer.order_status_changed', [
            'order_id' => $orderId,
            'status' => $newStatus,
            'message' => "وضعیت سفارش به '$newStatus' تغییر کرد.",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Vitrine listing approved
     */
    public function notifyListingApproved(int $listingId, int $sellerId): void
    {
        $this->sendMessage($sellerId, 'vitrine.listing_approved', [
            'listing_id' => $listingId,
            'message' => 'آگهی شما تایید شد و اکنون برای فروش فعال است.',
            'action_url' => "/vitrine/$listingId",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Dispute notification
     */
    public function notifyDisputeOpened(
        int    $disputeId,
        int    $userId,
        string $reason
    ): void {
        $this->sendMessage($userId, 'dispute.opened', [
            'dispute_id' => $disputeId,
            'reason' => $reason,
            'message' => 'یک اختلاف جدید باز شده است.',
            'action_url' => "/disputes/$disputeId",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Dispute resolved notification
     */
    public function notifyDisputeResolved(
        int    $disputeId,
        int    $userId,
        string $verdict
    ): void {
        $this->sendMessage($userId, 'dispute.resolved', [
            'dispute_id' => $disputeId,
            'verdict' => $verdict,
            'message' => "اختلاف با نتیجه '$verdict' حل شد.",
            'action_url' => "/disputes/$disputeId",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Payment received notification
     */
    public function notifyPaymentReceived(
        int    $userId,
        float  $amount,
        string $source,
        string $reference
    ): void {
        $this->sendMessage($userId, 'wallet.payment_received', [
            'amount' => $amount,
            'source' => $source,
            'reference' => $reference,
            'message' => "$amount تومان برای شما واریز شد.",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Database Cleanup
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Clean up expired messages
     * Run via cron: 0 * * * * (hourly)
     */
    public function cleanupExpiredMessages(): int
    {
        return $this->db->query(
            "DELETE FROM realtime_messages WHERE expires_at < NOW()"
        )->rowCount();
    }
}

