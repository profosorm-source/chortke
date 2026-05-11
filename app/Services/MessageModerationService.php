<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InteractionModel;
use App\Models\MessageModerationModel;
use Core\Database;
use App\Contracts\LoggerInterface;
class MessageModerationServiceextends \App\Services\BaseService
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
}

