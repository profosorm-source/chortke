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
    ) {
        parent::__construct($logger);
    }

    /**
     * باز کردن پرونده اختلاف برای سفارش اینفلوئنسر
     */
    public function openDispute(int $orderId, int $customerId, string $reason): array
    {
        $data = [
            'ref_type' => 'order',
            'ref_id' => $orderId,
            'user_id' => $customerId,
            'target_user_id' => null,
            'reason' => $reason
        ];
        
        $dispute = $this->openCase($data);
        if (!$dispute) {
            return ['success' => false, 'message' => 'خطا در باز کردن پرونده اختلاف.'];
        }
        
        return ['success' => true, 'dispute_id' => $dispute->id];
    }

    /**
     * ارسال پیام در پرونده اختلاف
     */
    public function sendMessage(int $disputeId, int $userId, string $role, string $message, ?string $attachment = null): array
    {
        $ok = $this->disputeModel->addMessage($disputeId, $userId, $message, $attachment, $role);
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ارسال پیام.'];
        }
        
        $this->logger->info('case.message_sent', [
            'dispute_id' => $disputeId,
            'user_id' => $userId,
            'role' => $role
        ]);
        
        return ['success' => true];
    }

    /**
     * حل پرونده اختلاف به صورت توافقی و دوستانه
     */
    public function resolveByAgreement(int $disputeId, int $initiatorId, string $resolution, string $verdict): array
    {
        $dispute = $this->disputeModel->getSafe($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }
        
        $ok = $this->disputeModel->update($disputeId, [
            'status' => Dispute::STATUS_RESOLVED_PEER,
            'resolution_note' => $resolution,
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolved_by' => $initiatorId
        ]);
        
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ثبت تفاهم‌نامه.'];
        }
        
        $this->logger->info('case.resolved_peer', [
            'dispute_id' => $disputeId,
            'resolved_by' => $initiatorId
        ]);
        
        $this->notificationService->send($dispute->user_id, 'system', 'حل اختلاف به صورت دوستانه', 'اختلاف سفارش شما به توافق طرفین خاتمه یافت.');
        if ($dispute->target_user_id) {
            $this->notificationService->send($dispute->target_user_id, 'system', 'حل اختلاف به صورت دوستانه', 'اختلاف سفارش شما به توافق طرفین خاتمه یافت.');
        }
        
        return ['success' => true];
    }

    /**
     * ارجاع پرونده به مدیر
     */
    public function escalateToAdmin(int $disputeId, int $requesterId): array
    {
        $dispute = $this->disputeModel->getSafe($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }
        
        if ($dispute->status !== Dispute::STATUS_OPEN_PEER && $dispute->status !== Dispute::STATUS_OPEN) {
            return ['success' => false, 'message' => 'امکان ارجاع این پرونده وجود ندارد.'];
        }
        
        $ok = $this->disputeModel->update($disputeId, [
            'status' => Dispute::STATUS_ESCALATED,
            'resolved_by' => $requesterId
        ]);
        
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ارجاع پرونده به ادمین.'];
        }
        
        $this->logger->info('case.escalated', [
            'dispute_id' => $disputeId,
            'requester_id' => $requesterId
        ]);
        
        return ['success' => true];
    }

    /**
     * حل پرونده اختلاف توسط مدیر سیستم
     */
    public function adminResolve(int $disputeId, int $adminId, string $verdict, string $note, float $refundPercent = 0): array
    {
        $dispute = $this->disputeModel->getSafe($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }
        
        $ok = $this->disputeModel->update($disputeId, [
            'status' => Dispute::STATUS_RESOLVED_ADMIN,
            'admin_decision' => $verdict,
            'admin_id' => $adminId,
            'admin_note' => $note,
            'refund_percent' => $refundPercent,
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolved_by' => $adminId
        ]);
        
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ثبت رای مدیر.'];
        }
        
        $this->logger->info('case.resolved_admin', [
            'dispute_id' => $disputeId,
            'admin_id' => $adminId,
            'verdict' => $verdict
        ]);
        
        $this->notificationService->send($dispute->user_id, 'system', 'رأی داوری صادر شد', 'داور سیستم رأی پرونده اختلاف را صادر کرد.');
        if ($dispute->target_user_id) {
            $this->notificationService->send($dispute->target_user_id, 'system', 'رأی داوری صادر شد', 'داور سیستم رأی پرونده اختلاف را صادر کرد.');
        }
        
        return ['success' => true];
    }

    /**
     * پردازش خودکار گفتگوهای منقضی شده طرفین
     */
    public function processExpiredPeerResolutions(): int
    {
        $expired = $this->db->fetchAll(
            "SELECT id FROM disputes 
             WHERE status = ? AND peer_deadline < NOW()",
            [Dispute::STATUS_OPEN_PEER]
        );
        
        $count = 0;
        foreach ($expired as $row) {
            $ok = $this->disputeModel->update((int)$row->id, [
                'status' => Dispute::STATUS_ESCALATED,
                'resolution_note' => 'سیستم: پایان زمان گفتگوی طرفین و ارجاع خودکار به مدیریت.'
            ]);
            
            if ($ok) {
                $count++;
                $this->logger->info('case.auto_escalated', ['dispute_id' => $row->id]);
            }
        }
        
        return $count;
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
