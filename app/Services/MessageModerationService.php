<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InteractionModel;
use App\Models\MessageModerationModel;
use Core\Database;
use App\Contracts\LoggerInterface;
class MessageModerationService
extends \App\Services\BaseService
{
    private Database $db;
    private InteractionModel $interactionModel;
    private MessageModerationModel $moderationModel;

    public function __construct(Database $db, LoggerInterface $logger, InteractionModel $interactionModel, MessageModerationModel $moderationModel)
    {
        parent::__construct($logger);
        $this->db = $db;
        $this->interactionModel = $interactionModel;
        $this->moderationModel = $moderationModel;
    }

    public function getReports(string $status, int $limit, int $offset): array
    {
        return [
            'reports' => $this->interactionModel->findMessageReportsPaginated($limit, $offset, $status),
            'total' => $this->interactionModel->countMessageReports($status),
        ];
    }

    public function getReportDetail(int $id): ?array
    {
        return $this->interactionModel->findMessageReportById($id);
    }

    public function getUserMessages(int $senderId, int $limit = 10): array
    {
        return $this->moderationModel->getUserMessages($senderId, $limit);
    }

    public function approveReport(int $reportId, string $action, int $adminId): array
    {
        $report = $this->interactionModel->findMessageReportById($reportId);
        if (!$report) {
            return ['success' => false, 'message' => 'گزارش یافت نشد'];
        }

        try {
            $this->db->beginTransaction();

            $this->moderationModel->updateReportStatus($reportId, 'resolved', $adminId);

            switch ($action) {
                case 'warn':
                    $this->warnUser((int)$report['sender_id']);
                    break;
                case 'delete':
                    $this->deleteMessage((int)$report['message_id']);
                    break;
                case 'ban':
                    $this->banUser((int)$report['sender_id']);
                    break;
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'گزارش تایید شد'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('message_moderation.approve_failed', [
                'report_id' => $reportId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در پردازش'];
        }
    }

    public function dismissReport(int $reportId, int $adminId): bool
    {
        return $this->moderationModel->updateReportStatus($reportId, 'dismissed', $adminId);
    }

    public function getBlockedUsers(int $limit, int $offset): array
    {
        return $this->moderationModel->getBlockedUsers($limit, $offset);
    }

    public function getBlockedUsersCount(): int
    {
        $count = $this->db->query("SELECT COUNT(*) as cnt FROM user_blocks")->fetch();
        return (int)($count['cnt'] ?? 0);
    }

    /**
     * دریافت رپورٹس (پہلے والا)
     */
    public function getStats(): array
    {
        $today = date('Y-m-d 00:00:00');

        // Combine all COUNT queries into single query to avoid N+1 problem
        $statsResult = $this->db->query(
            "SELECT
                (SELECT COUNT(*) FROM direct_messages) as total_messages,
                (SELECT COUNT(*) FROM message_reports) as total_reports,
                (SELECT COUNT(*) FROM message_reports WHERE status = 'pending') as pending_reports,
                (SELECT COUNT(*) FROM user_blocks) as total_blocks,
                (SELECT COUNT(*) FROM direct_messages WHERE created_at >= ?) as today_messages,
                (SELECT COUNT(*) FROM message_reports WHERE created_at >= ?) as today_reports",
            [$today, $today]
        )->fetch(\PDO::FETCH_ASSOC);

        $stats = [
            'total_messages' => (int)($statsResult['total_messages'] ?? 0),
            'total_reports' => (int)($statsResult['total_reports'] ?? 0),
            'pending_reports' => (int)($statsResult['pending_reports'] ?? 0),
            'total_blocks' => (int)($statsResult['total_blocks'] ?? 0),
            'today_messages' => (int)($statsResult['today_messages'] ?? 0),
            'today_reports' => (int)($statsResult['today_reports'] ?? 0),
        ];

        $topReporters = $this->db->query(
            "SELECT u.name, u.id, COUNT(*) as count
             FROM message_reports mr
             JOIN users u ON mr.reporter_id = u.id
             GROUP BY mr.reporter_id
             ORDER BY count DESC
             LIMIT 5"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'stats' => $stats,
            'top_reporters' => $topReporters,
        ];
    }

    private function deleteMessage(int $messageId): void
    {
        $this->db->query(
            "UPDATE direct_messages
             SET message = '[پیام حذف‌شده توسط مدیریت]', deleted_at = NOW(), deleted_by = 'admin'
             WHERE id = ?",
            [$messageId]
        );
    }

    private function warnUser(int $userId): void
    {
        $this->db->query(
            "UPDATE users
             SET warning_count = warning_count + 1
             WHERE id = ?",
            [$userId]
        );
    }

    private function banUser(int $userId): void
    {
        $this->db->query(
            "UPDATE users
             SET status = 'banned', banned_reason = 'Inappropriate messaging'
             WHERE id = ?",
            [$userId]
        );
    }

    /**
     * صفحہ بندی کے ساتھ رپورٹس حاصل کریں
     */
    public function getReportsPaginated(string $status = 'all', int $limit = 20, int $offset = 0): array
    {
        if ($status === 'all') {
            return $this->interactionModel->findMessageReportsPaginated($limit, $offset, null);
        }
        return $this->interactionModel->findMessageReportsPaginated($limit, $offset, $status);
    }

    /**
     * کل رپورٹس گنتی کریں
     */
    public function countReports(string $status = 'all'): int
    {
        if ($status === 'all') {
            return $this->interactionModel->countMessageReports(null);
        }
        return $this->interactionModel->countMessageReports($status);
    }

    /**
     * کاربر کو مسدود کریں
     */
    public function blockUser(int $userId, string $reason, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            // Check if user exists
            $user = $this->db->query("SELECT id FROM users WHERE id = ?", [$userId])->fetch(\PDO::FETCH_OBJ);
            if (!$user) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'کاربر یافت نشد'];
            }

            // Block the user
            $this->db->query(
                "INSERT INTO user_blocks (user_id, blocked_reason, blocked_by, blocked_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE blocked_reason = VALUES(blocked_reason), blocked_at = NOW()",
                [$userId, $reason, $adminId]
            );

            $this->db->commit();
            $this->logger->info('user.blocked', ['user_id' => $userId, 'admin_id' => $adminId, 'reason' => $reason]);
            return ['success' => true, 'message' => 'کاربر مسدود شد'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('user.block.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در مسدود کردن'];
        }
    }

    /**
     * کاربر کو رہا کریں (unblock)
     */
    public function unblockUser(int $userId): bool
    {
        try {
            $this->db->query("DELETE FROM user_blocks WHERE user_id = ?", [$userId]);
            $this->logger->info('user.unblocked', ['user_id' => $userId]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('user.unblock.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * پیام کو براہ راست حاصل کریں
     */
    public function getMessage(int $messageId): ?array
    {
        return $this->db->query(
            "SELECT * FROM direct_messages WHERE id = ?",
            [$messageId]
        )->fetch(\PDO::FETCH_ASSOC);
    }
}