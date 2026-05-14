<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class ContactMessage extends Model
{
    protected static string $table = 'contact_messages';

    public function createMessage(array $payload): int
    {
        return (int)$this->db->table(self::$table)
            ->insert($payload);
    }

    public function getPendingMessages(int $limit = 50): array
    {
        return $this->db->table(self::$table)
            ->select('id', 'name', 'email', 'subject', 'message', 'created_at')
            ->where('status', '=', 'pending')
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function markAsProcessed(int $messageId, string $status = 'processed'): bool
    {
        return (bool)$this->db->table(self::$table)
            ->where('id', '=', $messageId)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
