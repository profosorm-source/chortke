<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Contracts\NotificationServiceInterface;
use App\Services\ReconciliationService;
use App\Models\Dispute;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;

/**
 * DisputeService - سرویس اشتراکی مدیریت اختلافات و اعتراضات (Appeals)
 * 
 * این سرویس مدیریت چرخه‌حیات تمامی پرونده‌های اعتراضی و اختلافی را بر عهده دارد.
 */
class DisputeService
{
    private const LIMITS = [
        'daily' => 3,
        'weekly' => 10
    ];
    private ?\App\Services\OutboxService $outboxService = null;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private Dispute $disputeModel;
    private WalletServiceInterface $walletService;
    private ReconciliationService $reconciliationService;
    private \App\Models\Transaction $transactionModel;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Dispute $disputeModel,
        WalletServiceInterface $walletService,
        ReconciliationService $reconciliationService,
        \App\Models\Transaction $transactionModel,
        ?\App\Services\OutboxService $outboxService = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->disputeModel = $disputeModel;
        $this->walletService = $walletService;
        $this->reconciliationService = $reconciliationService;
        $this->transactionModel = $transactionModel;

        
        $this->outboxService = $outboxService;
    }

    /**
     * باز کردن پرونده اختلاف برای سفارش اینفلوئنسر
     */
    public function openDispute(int $orderId, int $customerId, string $reason): array
    {
        // 🔐 Architectural Fix: Ensure Order integrity and contextual ownership
        $order = $this->db->table('story_orders')
            ->where('id', '=', $orderId)
            ->first();

        if (!$order) {
            return ['success' => false, 'message' => 'سفارش معتبر یافت نشد.'];
        }

        $customerId = (int)$customerId;
        $buyerId = (int)($order->customer_id ?? 0);
        $sellerId = (int)($order->influencer_user_id ?? 0);

        if ($buyerId !== $customerId && $sellerId !== $customerId) {
            return ['success' => false, 'message' => 'شما دسترسی به طرح اختلاف برای این سفارش را ندارید.'];
        }

        // Auto-determine target counterparty safely
        $targetUserId = ($buyerId === $customerId) ? $sellerId : $buyerId;

        $data = [
            'ref_type' => 'order',
            'ref_id' => $orderId,
            'user_id' => $customerId,
            'target_user_id' => $targetUserId,
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\Dispute\ResolveDisputeByAgreementJob::class);
        return $job->handle($disputeId, $initiatorId, $resolution, $verdict);
    }


    /**
     * ارجاع پرونده به مدیر
     */
    public function escalateToAdmin(int $disputeId, int $requesterId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Dispute\EscalateDisputeToAdminJob::class);
        return $job->handle($disputeId, $requesterId);
    }


    /**
     * حل پرونده اختلاف توسط مدیر سیستم
     */
    public function adminResolve(int $disputeId, int $adminId, string $verdict, string $note, float $refundPercent = 0): array
    {
        // 🔒 Hardened Fix: Wrapping entire multi-step resolver in an Atomic DB Transaction
        return $this->getTransactionWrapper()->runWithRetry(function() use ($disputeId, $adminId, $verdict, $note, $refundPercent) {
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
                throw new \RuntimeException('Failed to record administrative arbitration verdict.');
            }
            
            $this->logger->info('case.resolved_admin', [
                'dispute_id' => $disputeId,
                'admin_id' => $adminId,
                'verdict' => $verdict
            ]);
            
            // پردازش استرداد وجه بر اساس ردیابی زنجیره تراکنش‌های مالی
            if ($refundPercent > 0) {
                // 🔐 Safe Architectural Refactor: Swapped dynamic RAW string lookup for hard-coded Transaction model helper.
                $originalTx = $this->transactionModel->findCompletedByReference((string)$dispute->ref_id, (string)$dispute->ref_type);

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
                        $payload = [
                            'user_id' => (int)$dispute->user_id,
                            'amount' => $refundAmount,
                            'currency' => $currency,
                            'metadata' => [
                                'type' => 'refund',
                                'description' => "استرداد وجه ({$refundPercent}٪) مربوط به حل اختلاف شماره {$disputeId}",
                                'ref_id' => $disputeId,
                                'ref_type' => 'dispute',
                                'admin_id' => $adminId
                            ],
                        ];

                        if ($this->outboxService) {
                            $ok = $this->outboxService->record('dispute', $disputeId, \App\Events\Registry\EventRegistry::DISPUTE_RESOLVED_REFUND, $payload);
                            $success = $ok === true;
                        } else {
                            $res = $this->walletService->deposit((int)$dispute->user_id, $refundAmount, $currency, [
                                'type' => 'refund',
                                'description' => "استرداد وجه ({$refundPercent}٪) مربوط به حل اختلاف شماره {$disputeId}",
                                'ref_id' => $disputeId,
                                'ref_type' => 'dispute',
                                'admin_id' => $adminId
                            ]);
                            $success = isset($res['success']) && $res['success'] === true;
                        }
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
                            'is_internal' => true,
                        ]);
                    } else {
                        throw new \RuntimeException("Atomic dispute reversal failed at Wallet core.");
                    }
                } else {
                    $this->logger->warning('case.refund_skipped_no_tx', [
                        'dispute_id' => $disputeId,
                        'ref_id' => $dispute->ref_id,
                        'ref_type' => $dispute->ref_type,
                        'message' => 'No matching completed transaction found to derive refund amount.'
                    ]);
                }
            }
            
            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => (int)$dispute->user_id,
                'type' => 'system',
                'title' => 'رأی داوری صادر شد',
                'message' => 'داور سیستم رأی پرونده اختلاف را صادر کرد.'
            ]);
            if ($dispute->target_user_id) {
                $this->eventDispatcher->dispatchAsync('notification.requested', [
                    'user_id' => (int)$dispute->target_user_id,
                    'type' => 'system',
                    'title' => 'رأی داوری صادر شد',
                    'message' => 'داور سیستم رأی پرونده اختلاف را صادر کرد.'
                ]);
            }
            
            return ['success' => true];
        });
    }

    /**
     * پردازش خودکار گفتگوهای منقضی شده طرفین
     */
    public function processExpiredPeerResolutions(): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Dispute\ProcessExpiredDisputesJob::class);
        return $job->handle();
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
     * ارسال پیام در پرونده با تعیین خودکار نقش و آپدیت تاریخچه
     */
    public function addMessageWithContext(int $disputeId, int $userId, string $message, ?string $attachment = null): array
    {
        $dispute = $this->disputeModel->find($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }

        // Security check
        if ((int)$dispute->user_id !== $userId && (int)($dispute->target_user_id ?? 0) !== $userId) {
            return ['success' => false, 'message' => 'شما دسترسی به این پرونده ندارید.'];
        }

        // Auto determine role
        $role = ((int)$dispute->user_id === $userId) ? 'creator' : 'opponent';

        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($disputeId, $userId, $message, $attachment, $role) {
                $ok = $this->disputeModel->addMessage($disputeId, $userId, $message, $attachment, $role);
                if (!$ok) throw new \Exception('خطا در ثبت پیام');
    
                $this->db->query("UPDATE disputes SET updated_at = NOW() WHERE id = ?", [$disputeId]);
                
                $this->logger->info('dispute.message_added', ['dispute_id' => $disputeId, 'user_id' => $userId, 'role' => $role]);
                
                return ['success' => true];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
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
