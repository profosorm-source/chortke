<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class TicketMessage extends Model {
/* -------------------------
     * Helpers (DB wrappers)
     * ------------------------- */
    private function fetchOne(string $sql, array $params = []): ?object
    {
        $stmt = $this->db->query($sql, $params);
        if (!$stmt) return null;

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    private function fetchAllRows(string $sql, array $params = []): array
    {
        $stmt = $this->db->query($sql, $params);
        if (!$stmt) return [];

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    private function execBool(string $sql, array $params = []): bool
    {
        $stmt = $this->db->query($sql, $params);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }

    /**
     * ایجاد پیام جدید
     */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO ticket_messages
                (ticket_id, user_id, message, attachments, is_admin, ip_address, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $attachments = null;
        if (isset($data['attachments'])) {
            $attachments = \is_array($data['attachments'])
                ? \json_encode($data['attachments'], JSON_UNESCAPED_UNICODE)
                : (string)$data['attachments'];
        }

        $ip = \function_exists('get_client_ip') ? get_client_ip() : null;

        $ok = $this->db->query($sql, [
            (int)($data['ticket_id'] ?? 0),
            (int)($data['user_id'] ?? 0),
            (string)($data['message'] ?? ''),
            $attachments,
            (int)!empty($data['is_admin']), // 0/1
            $ip,
        ]);

        if (!$ok) {
            return null;
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * دریافت پیام‌های تیکت
     */
    public function getByTicketId(int $ticketId): array
    {
        $sql = "SELECT tm.*, u.full_name, u.email
                FROM ticket_messages tm
                JOIN users u ON tm.user_id = u.id
                WHERE tm.ticket_id = ?
                ORDER BY tm.created_at ASC";

        return $this->fetchAllRows($sql, [(int)$ticketId]);
    }

    /**
     * علامت‌گذاری پیام‌های طرف مقابل به عنوان خوانده شده
     * اگر viewer ادمین باشد => پیام‌های کاربر (is_admin=0) خوانده شود
     * اگر viewer کاربر باشد => پیام‌های ادمین (is_admin=1) خوانده شود
     */
    public function markAsRead(int $ticketId, bool $viewerIsAdmin = false): bool
    {
        $senderIsAdmin = $viewerIsAdmin ? 0 : 1;

        $sql = "UPDATE ticket_messages
                SET is_read = 1, read_at = NOW(), updated_at = NOW()
                WHERE ticket_id = ?
                  AND is_admin = ?
                  AND is_read = 0";

        return $this->execBool($sql, [(int)$ticketId, (int)$senderIsAdmin]);
    }

    /**
     * شمارش پیام‌های خوانده نشده
     * - برای ادمین: پیام‌های کاربران (is_admin=0) که هنوز read نشده‌اند
     * - برای کاربر: پیام‌های ادمین برای تیکت‌های خودش که هنوز read نشده‌اند
     */
    public function countUnread(int $userId, bool $forAdmin = false): int
    {
        if ($forAdmin) {
            $sql = "SELECT COUNT(*) as count
                    FROM ticket_messages tm
                    JOIN tickets t ON tm.ticket_id = t.id
                    WHERE tm.is_admin = 0
                      AND tm.is_read = 0";
            $row = $this->fetchOne($sql);
        } else {
            $sql = "SELECT COUNT(*) as count
                    FROM ticket_messages tm
                    JOIN tickets t ON tm.ticket_id = t.id
                    WHERE t.user_id = ?
                      AND tm.is_admin = 1
                      AND tm.is_read = 0";
            $row = $this->fetchOne($sql, [(int)$userId]);
        }

        return (int)($row->count ?? 0);
    }
}