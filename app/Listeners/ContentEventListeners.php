<?php

declare(strict_types=1);

namespace App\Listeners;

use Core\Container;
use Core\Event;
use App\Contracts\LoggerInterface;
use App\Services\Gamification\XpService;
use App\Services\Shared\ReferralService;
use App\Contracts\WalletServiceInterface;
use App\Services\Notification\NotificationService;
use App\Services\Shared\IdempotencyService;
use App\Services\Cache\CacheInvalidationService;
use App\Enums\ModuleContext;

/**
 * Content Event Listeners
 * 
 * مجموعه‌ای از Listener‌های محتوایی برای پاسخ‌دهی به رویدادهای مختلف سیکل حیات محتوا
 * شامل: تایید، رد، انتشار، ثبت درآمد و پرداخت
 * 
 * یکی از خروجی‌های Event-Driven Decoupling است که وابستگی‌های ContentService را کاهش می‌دهد
 * 
 * @package App\Listeners
 */
class ContentEventListeners
{
    protected LoggerInterface $logger;
    protected XpService $xpService;
    protected ReferralService $referralService;
    protected NotificationService $notificationService;
    protected CacheInvalidationService $cacheInvalidationService;
    protected WalletServiceInterface $walletService;
    protected ?\App\Services\OutboxService $outbox;
    protected IdempotencyService $idempotencyService;
    public function __construct(
        LoggerInterface $logger,
        XpService $xpService,
        ReferralService $referralService,
        NotificationService $notificationService,
        CacheInvalidationService $cacheInvalidationService,
        WalletServiceInterface $walletService,
        ?\App\Services\OutboxService $outbox = null,
        IdempotencyService $idempotencyService
    ) {        $this->logger = $logger;
        $this->xpService = $xpService;
        $this->referralService = $referralService;
        $this->notificationService = $notificationService;
        $this->cacheInvalidationService = $cacheInvalidationService;
        $this->walletService = $walletService;
        $this->outbox = $outbox;
        $this->idempotencyService = $idempotencyService;

    }

    /**
     * ContentApprovedListener
     * 
     * وقتی محتوا تایید می‌شود:
     * - XP به کاربر اعطا می‌شود
     * - بونوس Referral بررسی می‌شود
     * - کاربر مطلع می‌شود
     */
    public function handleContentApproved(Event $event): void
    {
        try {
            $data = $event->getData();
            $submissionId = $data['submission_id'] ?? null;
            $userId = $data['user_id'] ?? null;

            if (!$submissionId || !$userId) {
                $this->logger->warning('content.approved.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.approved.started', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

            // 1. اعطای XP به کاربر
            $this->awardContentApprovalXp((int)$userId, (int)$submissionId);

            // 2. بررسی بونوس Referral
            $this->processReferralBonus((int)$userId, (int)$submissionId);

            // 3. اطلاع‌رسانی به کاربر
            $this->notifyContentApproved((int)$userId, (int)$submissionId);

            // 4. باطل‌سازی کش جستجو
            $this->invalidateContentCache();

            $this->logger->info('content.approved.completed', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.approved.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ContentRejectedListener
     * 
     * وقتی محتوا رد می‌شود:
     * - کاربر مطلع می‌شود
     */
    public function handleContentRejected(Event $event): void
    {
        try {
            $data = $event->getData();
            $submissionId = $data['submission_id'] ?? null;
            $userId = $data['user_id'] ?? null;
            $reason = $data['reason'] ?? '';

            if (!$submissionId || !$userId) {
                $this->logger->warning('content.rejected.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.rejected.started', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

            // اطلاع‌رسانی به کاربر
            $this->notifyContentRejected((int)$userId, (int)$submissionId, $reason);

            $this->logger->info('content.rejected.completed', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.rejected.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ContentPublishedListener
     * 
     * وقتی محتوا منتشر می‌شود:
     * - کش جستجو باطل می‌شود
     * - اطلاع‌رسانی صورت می‌گیرد
     */
    public function handleContentPublished(Event $event): void
    {
        try {
            $data = $event->getData();
            $submissionId = $data['submission_id'] ?? null;
            $userId = $data['user_id'] ?? null;

            if (!$submissionId || !$userId) {
                $this->logger->warning('content.published.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.published.started', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

            // باطل‌سازی کش جستجو
            $this->invalidateContentCache();

            // اطلاع‌رسانی
            $this->notifyContentPublished((int)$userId, (int)$submissionId);

            $this->logger->info('content.published.completed', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.published.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ContentRevenueRecordedListener
     * 
     * وقتی درآمد محتوا ثبت می‌شود:
     * - Audit trail ثبت می‌شود
     */
    public function handleContentRevenueRecorded(Event $event): void
    {
        try {
            $data = $event->getData();
            $submissionId = $data['submission_id'] ?? null;
            $userId = $data['user_id'] ?? null;
            $revenueId = $data['revenue_id'] ?? null;
            $period = $data['period'] ?? null;

            if (!$submissionId || !$userId || !$revenueId) {
                $this->logger->warning('content.revenue_recorded.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.revenue_recorded.completed', [
                'submission_id' => $submissionId,
                'revenue_id' => $revenueId,
                'user_id' => $userId,
                'period' => $period
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.revenue_recorded.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ContentRevenuePaidListener
     * 
     * وقتی درآمد محتوا پرداخت می‌شود:
     * - مبلغ به کیف پول کاربر افزوده می‌شود
     * - اطلاع‌رسانی صورت می‌گیرد
     */
    public function handleContentRevenuePaid(Event $event): void
    {
        try {
            $data = $event->getData();
            $revenueId = $data['revenue_id'] ?? null;
            $userId = $data['user_id'] ?? null;
            $amount = $data['amount'] ?? null;

            if (!$revenueId || !$userId || !$amount) {
                $this->logger->warning('content.revenue_paid.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.revenue_paid.started', [
                'revenue_id' => $revenueId,
                'user_id' => $userId,
                'amount' => $amount
            ]);

            // واریز به کیف پول
            $this->depositToWallet((int)$userId, (float)$amount, 'content_revenue', (int)$revenueId);

            // اطلاع‌رسانی
            $this->notifyContentRevenuePaid((int)$userId, (float)$amount);

            $this->logger->info('content.revenue_paid.completed', [
                'revenue_id' => $revenueId,
                'user_id' => $userId,
                'amount' => $amount
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.revenue_paid.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ──────────────────────────────────────────────────────────
     * HELPER METHODS
     * ──────────────────────────────────────────────────────────
     */

    /**
     * اعطای XP برای تایید محتوا
     */
    private function awardContentApprovalXp(int $userId, int $submissionId): void
    {
        try {
            $xpService = $this->xpService;

            // 50 XP برای تایید محتوا
            $baseXp = 50.0;
            $idempotencyKey = "content_approved_{$submissionId}";

            $success = $xpService->award(
                userId: $userId,
                context: ModuleContext::CONTENT,
                baseXp: $baseXp,
                idempotencyKey: $idempotencyKey
            );

            if ($success) {
                $this->logger->info('content.xp_awarded', [
                    'user_id' => $userId,
                    'xp' => $baseXp,
                    'submission_id' => $submissionId
                ]);
            } else {
                $this->logger->warning('content.xp_award_failed', [
                    'user_id' => $userId,
                    'submission_id' => $submissionId
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('content.xp_award_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * پردازش بونوس Referral
     */
    private function processReferralBonus(int $userId, int $submissionId): void
    {
        try {
            $referralService = $this->referralService;

            // بررسی اینکه آیا این کاربر از طریق referral وارد شده است
            $referralResult = $referralService->checkAndAwardBonus(
                userId: $userId,
                context: 'content_approval',
                referenceId: $submissionId
            );

            if ($referralResult) {
                $this->logger->info('content.referral_bonus_awarded', [
                    'user_id' => $userId,
                    'submission_id' => $submissionId
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('content.referral_bonus_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * اطلاع‌رسانی تایید محتوا
     */
    private function notifyContentApproved(int $userId, int $submissionId): void
    {
        try {
            $notificationService = $this->notificationService;

            $notificationService->notify(
                userId: $userId,
                type: 'content_approved',
                title: 'محتوای شما تایید شد',
                message: 'محتوای ارسالی شما توسط تیم مدیریت تایید شده است.',
                data: [
                    'submission_id' => $submissionId,
                    'action_url' => "/content/{$submissionId}"
                ]
            );

            $this->logger->info('content.approved_notification_sent', [
                'user_id' => $userId,
                'submission_id' => $submissionId
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('content.approved_notification_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * اطلاع‌رسانی رد محتوا
     */
    private function notifyContentRejected(int $userId, int $submissionId, string $reason): void
    {
        try {
            $notificationService = $this->notificationService;

            $notificationService->notify(
                userId: $userId,
                type: 'content_rejected',
                title: 'محتوای شما رد شد',
                message: "دلیل رد: {$reason}",
                data: [
                    'submission_id' => $submissionId,
                    'reason' => $reason
                ]
            );

            $this->logger->info('content.rejected_notification_sent', [
                'user_id' => $userId,
                'submission_id' => $submissionId
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('content.rejected_notification_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * اطلاع‌رسانی انتشار محتوا
     */
    private function notifyContentPublished(int $userId, int $submissionId): void
    {
        try {
            $notificationService = $this->notificationService;

            $notificationService->notify(
                userId: $userId,
                type: 'content_published',
                title: 'محتوای شما منتشر شد',
                message: 'محتوای شما در کانال‌های پلتفرم منتشر شده است.',
                data: [
                    'submission_id' => $submissionId,
                    'action_url' => "/content/{$submissionId}"
                ]
            );

            $this->logger->info('content.published_notification_sent', [
                'user_id' => $userId,
                'submission_id' => $submissionId
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('content.published_notification_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * اطلاع‌رسانی پرداخت درآمد
     */
    private function notifyContentRevenuePaid(int $userId, float $amount): void
    {
        try {
            $notificationService = $this->notificationService;

            $notificationService->notify(
                userId: $userId,
                type: 'content_revenue_paid',
                title: 'درآمد محتوای شما پرداخت شد',
                message: "مبلغ {$amount} تومان به کیف پول شما واریز شده است.",
                data: [
                    'amount' => $amount,
                    'action_url' => '/wallet'
                ]
            );

            $this->logger->info('content.revenue_paid_notification_sent', [
                'user_id' => $userId,
                'amount' => $amount
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('content.revenue_paid_notification_exception', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * باطل‌سازی کش محتوا
     */
    private function invalidateContentCache(): void
    {
        try {
            $cacheInvalidationService = $this->cacheInvalidationService;

            $cacheInvalidationService->invalidateModuleSearch('content');

            $this->logger->info('content.cache_invalidated');
        } catch (\Throwable $e) {
            $this->logger->error('content.cache_invalidation_exception', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * واریز به کیف پول
     */
    private function depositToWallet(int $userId, float $amount, string $type, int $referenceId): void
    {
        try {
            $walletService = $this->walletService;
            $outbox = $this->outbox;

            $idemKey = $type . ':' . $referenceId;
            $payload = [
                'user_id' => (int)$userId,
                'amount' => (string)$amount,
                'currency' => 'irt',
                'metadata' => [
                    'type' => $type,
                    'reference_id' => $referenceId,
                    'description' => "درآمد محتوا (ID: {$referenceId})",
                    'idempotency_key' => $idemKey,
                ],
            ];

            if ($outbox) {
                $ok = $outbox->record('content_revenue', $referenceId, 'wallet.deposit.requested', $payload);
                if ($ok) {
                    $this->logger->info('content.wallet_deposit_queued', [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'reference_id' => $referenceId
                    ]);
                } else {
                    $this->logger->warning('content.wallet_deposit_queue_failed', [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'reference_id' => $referenceId
                    ]);
                }
                return;
            }

            $result = $this->idempotencyService->executeWithTransaction(
                'wallet.deposit',
                $userId,
                $payload,
                function () use ($walletService, $userId, $amount, $payload) {
                    return $walletService->deposit($userId, (string)$amount, $payload['currency'] ?? 'irt', $payload['metadata']);
                },
                $idemKey
            );

            if ($result) {
                $this->logger->info('content.wallet_deposit_success', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'reference_id' => $referenceId
                ]);
            } else {
                $this->logger->warning('content.wallet_deposit_failed', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'reference_id' => $referenceId
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('content.wallet_deposit_exception', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
        }
    }
}
