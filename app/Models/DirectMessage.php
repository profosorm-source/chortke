<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * DirectMessage Model
 */
class DirectMessage extends Model
{
    protected static string $table = 'direct_messages';

    public function createMessage(int $senderId, int $recipientId, string $message, bool $isEncrypted): ?int
    {
        $id = $this->db->table(static::$table)->insert([
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'message' => $message,
            'is_encrypted' => $isEncrypted ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $id ? (int)$id : null;
    }

    public function addAttachments(int $messageId, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $this->db->table('message_attachments')->insert([
                'message_id' => $messageId,
                'filename' => $attachment['filename'],
                'file_path' => $attachment['file_path'],
                'file_size' => $attachment['file_size'],
                'mime_type' => $attachment['mime_type'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    public function updateConversation(int $senderId, int $recipientId, int $messageId): void
    {
        $sql = "INSERT INTO user_conversations (user1_id, user2_id, last_message_id, updated_at)
                VALUES (LEAST(?, ?), GREATEST(?, ?), ?, NOW())
                ON DUPLICATE KEY UPDATE last_message_id = ?, updated_at = NOW()";

        $this->db->query($sql, [$senderId, $recipientId, $senderId, $recipientId, $messageId, $messageId]);
    }

    public function getConversation(int $userId, int $otherUserId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->table(static::$table . ' as dm')
            ->select('dm.*', 'u.full_name as sender_name')
            ->selectRaw('COUNT(da.id) as attachment_count')
            ->join('users as u', 'dm.sender_id', '=', 'u.id')
            ->leftJoin('message_attachments as da', 'dm.id', '=', 'da.message_id')
            ->where(function ($q) use ($userId, $otherUserId) {
                $q->where(function ($sub1) use ($userId, $otherUserId) {
                    $sub1->where('dm.sender_id', '=', $userId)
                         ->where('dm.recipient_id', '=', $otherUserId);
                })->orWhere(function ($sub2) use ($userId, $otherUserId) {
                    $sub2->where('dm.sender_id', '=', $otherUserId)
                         ->where('dm.recipient_id', '=', $userId);
                });
            })
            ->groupBy('dm.id')
            ->orderBy('dm.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public function getConversations(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min((int)$limit, 100));
        $offset = max(0, (int)$offset);

        $sql = "SELECT 
                    CASE 
                        WHEN uc.user1_id = ? THEN uc.user2_id 
                        ELSE uc.user1_id 
                    END as user_id,
                    u.full_name,
                    u.avatar,
                    dm.message as last_message,
                    dm.is_encrypted,
                    uc.updated_at as last_message_at,
                    COALESCE(unread.cnt, 0) as unread_count
                FROM user_conversations uc
                JOIN users u ON u.id = CASE WHEN uc.user1_id = ? THEN uc.user2_id ELSE uc.user1_id END
                LEFT JOIN direct_messages dm ON dm.id = uc.last_message_id
                LEFT JOIN (
                    SELECT sender_id, COUNT(*) as cnt 
                    FROM direct_messages 
                    WHERE recipient_id = ? AND read_at IS NULL 
                    GROUP BY sender_id
                ) unread ON unread.sender_id = u.id
                WHERE uc.user1_id = ? OR uc.user2_id = ?
                ORDER BY uc.updated_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(4, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(5, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(6, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(7, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function getUserInfo(int $userId): ?object
    {
        return $this->db->table('users')
            ->select('id', 'username', 'full_name', 'avatar', 'is_online', 'status')
            ->where('id', '=', $userId)
            ->where('status', '=', 'active')
            ->first();
    }

    public function findMessageById(int $messageId): ?object
    {
        return $this->db->table(static::$table)
            ->where('id', '=', $messageId)
            ->first();
    }

    public function softDeleteMessage(int $messageId, int $userId): bool
    {
        return (bool)$this->db->table(static::$table)
            ->where('id', '=', $messageId)
            ->where('sender_id', '=', $userId)
            ->update([
                'deleted_by' => $userId,
                'deleted_at' => date('Y-m-d H:i:s')
            ]);
    }

    public function addReaction(int $messageId, int $userId, string $emoji): bool
    {
        $sql = "INSERT INTO message_reactions (message_id, user_id, emoji, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE emoji = ?";

        return (bool)$this->db->query($sql, [$messageId, $userId, $emoji, $emoji]);
    }

    public function markAsRead(int $userId, int $otherUserId): void
    {
        $this->db->table(static::$table)
            ->where('recipient_id', '=', $userId)
            ->where('sender_id', '=', $otherUserId)
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public function isBlocked(int $userId, int $blockedUserId): bool
    {
        return (bool)$this->db->table('user_blocks')
            ->where('blocker_id', '=', $blockedUserId)
            ->where('blocked_id', '=', $userId)
            ->first();
    }

    public function hasConversation(int $userId, int $otherUserId): bool
    {
        $user1 = min($userId, $otherUserId);
        $user2 = max($userId, $otherUserId);
        
        return (bool)$this->db->table('user_conversations')
            ->where('user1_id', '=', $user1)
            ->where('user2_id', '=', $user2)
            ->first();
    }

    public function countUnread(int $userId, ?int $fromUserId = null): int
    {
        $query = $this->db->table(static::$table)
            ->where('recipient_id', '=', $userId)
            ->whereNull('read_at');

        if ($fromUserId !== null) {
            $query->where('sender_id', '=', $fromUserId);
        }

        return $query->count();
    }
}
