<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Services\Notification\NotificationService;
use App\Services\ReconciliationService;
use App\Models\Dispute;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;

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
        private WalletServiceInterface $walletService,
        private ReconciliationService $reconciliationService
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
            'resolved_at' => date('Y-m-d H:i:s')
        ]);
        
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ثبت رای مدیر.'];
        }
        
        $this->logger->info('case.resolved_admin', [
            'dispute_id' => $disputeId,
            'admin_id' => $adminId,
            'verdict' => $verdict
        ]);
        
        // پردازش استرداد وجه بر اساس ردیابی زنجیره تراکنش‌های مالی (Flawless Money-Trail Lookup)
        if ($refundPercent > 0) {
            try {
                // یافتن تراکنش اصلی که برای این مرجع (Ad, Task, etc) ثبت شده بود
                $originalTx = $this->db->query(
                    "SELECT * FROM transactions 
                     WHERE ref_id = ? AND ref_type = ? AND status = 'completed' 
                     ORDER BY created_at DESC LIMIT 1",
                    [(string)$dispute->ref_id, (string)$dispute->ref_type]
                )->fetch();

                if ($originalTx && isset($originalTx->amount)) {
                    $baseAmount = abs((float)$originalTx->amount);
                    $currency = $originalTx->currency ?? 'irt';
                    $refundAmount = ($baseAmount * $refundPercent) / 100.0;

                    $success = false;

                    // سناریوی ۱: بازگشت ۱۰۰٪ وجه - استفاده از سیستم اتمیک reverse
                    if ((int)$refundPercent === 100 && method_exists($this->walletService, 'reverseTransaction')) {
                        $success = $this->walletService->reverseTransaction(
                            $originalTx->transaction_id, 
                            $adminId, 
                            "استرداد کامل (۱۰۰٪) وجه مربوط به رأی اختلاف شماره {$disputeId}"
                        );
                    } else {
                        // سناریوی ۲: بازگشت جزئی (درصدی) یا روش جایگزین
                        $res = $this->walletService->deposit((int)$dispute->user_id, $refundAmount, $currency, [
                            'type' => 'refund',
                            'description' => "استرداد وجه ({$refundPercent}٪) مربوط به حل اختلاف شماره {$disputeId}",
                            'ref_id' => $disputeId,
                            'ref_type' => 'dispute',
                            'admin_id' => $adminId
                        ]);
                        $success = isset($res['success']) && $res['success'] === true;
                    }

                    if ($success) {
                        $this->logger->info('case.refund_processed', [
                            'dispute_id' => $disputeId,
                            'refund_amount' => $refundAmount,
                            'currency' => $currency,
                            'percent' => $refundPercent,
                            'user_id' => $dispute->user_id,
                            'is_reversal' => ((int)$refundPercent === 100)
                        ]);

                        // تطبیق نهایی پرداخت با دفتر کل
                        $this->reconciliationService->reconcilePayment([
                            'transaction_id' => 'dispute_refund_' . $disputeId . '_' . time(),
                            'reference_id' => 'dispute_' . $disputeId,
                            'order_id' => (int)$dispute->ref_id,
                            'amount' => $refundAmount,
                            'currency' => $currency,
                            'status' => 'success',
                            'gateway' => 'system_refund',
                            'user_id' => (int)$dispute->user_id,
                            'description' => "تطبیق خودکار استرداد رأی اختلاف",
                            'timestamp' => time(),
                        ]);
                    } else {
                        throw new \RuntimeException("Wallet operation failed during refund execution.");
                    }
                } else {
                    $this->logger->warning('case.refund_skipped_no_tx', [
                        'dispute_id' => $disputeId,
                        'ref_id' => $dispute->ref_id,
                        'ref_type' => $dispute->ref_type,
                        'message' => 'No matching completed transaction found to derive refund amount.'
                    ]);
                }
            } catch (\Throwable $refundEx) {
                $this->logger->error('case.refund_failed', [
                    'dispute_id' => $disputeId,
                    'error' => $refundEx->getMessage()
                ]);
                // اصل ثبت رأی اختلاف قبلاً در دیتابیس ذخیره شده، ولی استرداد ناموفق لاگ شد
            }
        }
        
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
     * کاربر کے تمام اختلافات حاصل کریں
     */
    public function getUserDisputes(int $userId, int $limit = 20, int $offset = 0): array
    {
        $disputes = $this->db->query("
            SELECT d.*, 
                   COALESCE(cu.full_name, 'کاربر') as creator_name,
                   COALESCE(tu.full_name, 'طرف مقابل') as target_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users tu ON tu.id = d.target_user_id
            WHERE d.user_id = ? OR d.target_user_id = ?
            ORDER BY d.updated_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $userId, $limit, $offset])->fetchAll(\PDO::FETCH_OBJ) ?? [];

        return $disputes;
    }

    /**
     * کاربر کے اختلافات کی تعداد گنتی کریں
     */
    public function countUserDisputes(int $userId): int
    {
        $result = $this->db->query("
            SELECT COUNT(*) as total
            FROM disputes
            WHERE user_id = ? OR target_user_id = ?
        ", [$userId, $userId])->fetch(\PDO::FETCH_OBJ);

        return $result->total ?? 0;
    }

    /**
     * dispute کو ID سے تلاش کریں
     */
    public function find(int $id): ?object
    {
        return $this->disputeModel->find($id);
    }

    /**
     * Dispute کے پیام حاصل کریں
     */
    public function getMessages(int $disputeId): array
    {
        return $this->disputeModel->getMessages($disputeId) ?? [];
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
}
