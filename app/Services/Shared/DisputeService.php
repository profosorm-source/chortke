<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Services\Notification\NotificationService;
use App\Models\Dispute;
use App\Models\Appeal;

use App\Contracts\LoggerInterface;
/**
 * DisputeService - سرویس اشتراکی مدیریت اختلافات و اعتراضات (Appeals)
 * 
 * این سرویس مدیریت چرخه‌حیات تمامی پرونده‌های اعتراضی و اختلافی را بر عهده دارد.
 */
class DisputeService extends \App\Services\BaseService
{
    private const LIMITS = [
        'daily' => 3,
        'weekly' => 10
    ];

    public function __construct(
        private Database $db,
        protected LoggerInterface $logger,
        private NotificationService $notificationService,
        private Dispute $disputeModel,
        private Appeal $appealModel
    ) {}

    /**
     * باز کردن پرونده اختلاف برای سفارش اینفلوئنسر
     */
    public function openDispute(int $orderId, int $customerId, string $reason): array
    {
        throw new \Exception('Method not implemented yet');
    }

    public function sendMessage(int $disputeId, int $userId, string $role, string $message, ?string $attachment = null): array
    {
        throw new \Exception('Method not implemented yet');
    }

    public function resolveByAgreement(int $disputeId, int $initiatorId, string $resolution, string $verdict): array
    {
        throw new \Exception('Method not implemented yet');
    }

    public function escalateToAdmin(int $disputeId, int $requesterId): array
    {
        throw new \Exception('Method not implemented yet');
    }

    public function adminResolve(int $disputeId, int $adminId, string $verdict, string $note, float $refundPercent = 0): array
    {
        throw new \Exception('Method not implemented yet');
    }

    public function processExpiredPeerResolutions(): int
    {
        throw new \Exception('Method not implemented yet');
    }

    /**
     * باز کردن پرونده جدید (اختلاف یا اعتراض)
     */
    public function openCase(array $data): ?object
    {
        // بررسی محدودیت‌ها برای کاربر
        if (!$this->checkLimits($data['user_id'])) {
            throw new \Exception('تعداد موارد ارسالی بیش از حد مجاز است.');
        }

        try {
            $data['priority'] = $this->determinePriority($data['ref_type'] ?? 'general');
            
            $dispute = $this->disputeModel->create($data);
            
            if ($dispute) {
                $this->logger->info('case.opened', [
                    'id' => $dispute->id,
                    'type' => $data['ref_type'],
                    'user_id' => $data['user_id']
                ]);
                
                // نوتیفیکیشن به طرفین یا ادمین
                $this->sendNotifications($dispute);
            }
            
            return $dispute;
        } catch (\Throwable $e) {
            $this->logger->error('case.open_failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ارسال پیام در پرونده
     */
    public function addMessage(int $caseId, int $userId, string $message, ?string $attachment = null): bool
    {
        return $this->disputeModel->addMessage($caseId, $userId, $message, $attachment);
    }

    /**
     * بررسی محدودیت‌های ارسال کاربر
     */
    private function checkLimits(int $userId): bool
    {
        // در مدل پیاده‌سازی می‌شود
        return true; 
    }

    /**
     * تعیین اولویت پرونده
     */
    private function determinePriority(string $type): string
    {
        $priorities = [
            'fraud_suspension' => 'urgent',
            'payment_dispute' => 'high',
            'order_dispute' => 'medium'
        ];
        return $priorities[$type] ?? 'low';
    }

    private function sendNotifications($case): void
    {
        // ارسال نوتیف به ادمین یا طرف مقابل
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Appeal (اعتراضات) Methods
    // ═══════════════════════════════════════════════════════════════════════

    public function submitAppeal(int $userId, string $appealType, string $title, string $description, ?int $referenceId = null, ?string $referenceType = null, array $attachments = []): array
    {
        if (!$this->canSubmitAppeal($userId)) {
            throw new \Exception('Appeal submission limit exceeded');
        }

        $appealId = $this->appealModel->create($userId, $appealType, $title, $description, $referenceId, $referenceType, $this->determinePriority($appealType));
        if (!$appealId) throw new \Exception('Failed to create appeal');

        foreach ($attachments as $attachment) {
            $this->appealModel->addAttachment($appealId, $userId, $attachment);
        }

        $this->appealModel->incrementUserAppealCount($userId);

        $this->logger->info("Appeal submitted", ['appeal_id' => $appealId, 'user_id' => $userId, 'type' => $appealType]);

        return ['appeal_id' => $appealId, 'auto_decision' => false];
    }

    public function canSubmitAppeal(int $userId): bool
    {
        $userStats = $this->appealModel->getUserAppealCount($userId);
        if ($userStats->appeal_banned_until && strtotime($userStats->appeal_banned_until) > time()) return false;

        $dailyCount = $this->appealModel->getDailyAppealCount($userId, date('Y-m-d'));
        if ($dailyCount >= self::LIMITS['daily']) return false;

        $weeklyCount = $this->appealModel->getWeeklyAppealCount($userId, date('Y-m-d H:i:s', strtotime('-7 days')));
        if ($weeklyCount >= self::LIMITS['weekly']) return false;

        return true;
    }

    public function getAppealsForAdmin(?string $status, ?string $priority, int $limit, int $offset): array
    {
        return $this->appealModel->getAppealsByStatus($status, $priority, $limit, $offset);
    }

    public function getAppealTemplates(string $type): array
    {
        return [
            'suspension' => ['title' => 'اعتراض به تعلیق حساب', 'description' => 'لطفا دلایل خود را توضیح دهید...'],
            'kyc_rejection' => ['title' => 'اعتراض به رد احراز هویت', 'description' => 'مدارک تکمیلی خود را ارسال کنید...'],
            'order_dispute' => ['title' => 'اختلاف در سفارش', 'description' => 'جزئیات مشکل در سفارش را بنویسید...'],
            'influencer_verification' => ['title' => 'اعتراض به رد تایید اینفلوئنسر', 'description' => 'لینک شبکه‌های اجتماعی خود را مجدد ارسال کنید...']
        ][$type] ?? ['title' => '', 'description' => ''];
    }

    public function getAppealStats(): array
    {
        $stats = $this->db->query("SELECT status, COUNT(*) as count FROM appeals GROUP BY status")->fetchAll();
        $formatted = ['pending' => 0, 'under_review' => 0, 'approved' => 0, 'rejected' => 0, 'escalated' => 0, 'total' => 0];
        
        foreach ($stats as $stat) {
            $formatted[$stat['status']] = (int)$stat['count'];
            $formatted['total'] += (int)$stat['count'];
        }
        return $formatted;
    }

    public function getAppealDetailsForAdmin(int $appealId): ?array
    {
        $appeal = $this->appealModel->findAppeal($appealId);
        if (!$appeal) return null;

        $appeal['attachments'] = $this->appealModel->getAttachments($appealId);
        $appeal['responses'] = $this->appealModel->getResponses($appealId);
        return $appeal;
    }

    public function getAppealDetails(int $appealId, int $userId): ?array
    {
        $appeal = $this->appealModel->findAppealForUser($appealId, $userId);
        if (!$appeal) return null;

        $appeal['attachments'] = $this->appealModel->getAttachments($appealId);
        $appeal['responses'] = $this->appealModel->getResponses($appealId);
        return $appeal;
    }

    public function respondToAppeal(int $appealId, int $adminId, string $response, ?string $newStatus = null, ?string $internalNotes = null): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query(
                "INSERT INTO appeal_responses (appeal_id, admin_id, response, internal_notes, created_at) VALUES (?, ?, ?, ?, NOW())",
                [$appealId, $adminId, $response, $internalNotes]
            );

            if ($newStatus) {
                $this->db->query("UPDATE appeals SET status = ?, updated_at = NOW() WHERE id = ?", [$newStatus, $appealId]);
            }

            $this->db->commit();
            $this->logger->info("Appeal response added", ['appeal_id' => $appealId, 'admin_id' => $adminId]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function banUserFromAppeals(int $userId, int $days, string $reason): void
    {
        $bannedUntil = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $this->db->query("UPDATE users SET appeal_banned_until = ? WHERE id = ?", [$bannedUntil, $userId]);
        $this->logger->info("User banned from appeals", ['user_id' => $userId, 'days' => $days, 'reason' => $reason]);
    }

    public function searchAppeals(string $query, ?string $status, int $limit): array
    {
        $conditions = ["(a.title LIKE ? OR a.description LIKE ? OR u.username LIKE ? OR u.email LIKE ?)"];
        $params = ["%{$query}%", "%{$query}%", "%{$query}%", "%{$query}%"];

        if ($status) {
            $conditions[] = "a.status = ?";
            $params[] = $status;
        }

        $where = "WHERE " . implode(" AND ", $conditions);
        $params[] = $limit;

        return $this->db->query(
            "SELECT a.*, u.username, u.email FROM appeals a LEFT JOIN users u ON a.user_id = u.id {$where} ORDER BY a.created_at DESC LIMIT ?",
            $params
        )->fetchAll() ?? [];
    }

    public function getAttachmentById(int $attachmentId): ?array
    {
        $result = $this->db->query("SELECT * FROM appeal_attachments WHERE id = ?", [$attachmentId])->fetch();
        return $result ?: null;
    }

    public function getAppealTrend(int $days): array
    {
        return $this->db->query(
            "SELECT DATE(created_at) as date, COUNT(*) as count FROM appeals WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(created_at) ORDER BY date ASC",
            [$days]
        )->fetchAll() ?? [];
    }

    public function getAppealTypeStats(): array
    {
        return $this->db->query("SELECT appeal_type, COUNT(*) as count FROM appeals GROUP BY appeal_type")->fetchAll() ?? [];
    }

    public function getUserAppeals(int $userId, int $limit, int $offset): array
    {
        return $this->appealModel->getUserAppeals($userId, $limit, $offset);
    }

    public function addMessageToAppeal(int $appealId, int $userId, string $message): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO appeal_responses (appeal_id, admin_id, response, is_user_message, created_at) VALUES (?, ?, ?, 1, NOW())",
            [$appealId, $userId, $message]
        );
    }
}

