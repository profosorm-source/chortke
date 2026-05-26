<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Ads;
use App\Models\CryptoDeposit;
use App\Models\CustomTaskSubmissionModel;
use App\Models\EmailQueue;
use App\Models\KYCVerification;
use App\Models\SecurityModel;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Core\Database;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;

/**
 * CronService — لایه Service برای عملیات Cron Jobs
 */
class CronService extends \App\Services\BaseService
{
    private ?\App\Services\OutboxService $outboxService = null;

    public function __construct(
        ?Database $db,
        private ActivityLog          $logModel,
        private Ads                  $adModel,
        private CryptoDeposit        $cryptoDepositModel,
        private EmailQueue           $emailQueueModel,
        private KYCVerification      $kycModel,
        private SecurityModel        $securityModel,
        private Transaction          $transactionModel,
        private User                 $userModel,
        private CustomTaskSubmissionModel $taskSubmissionModel,
        private WalletService        $walletService,
        LoggerInterface    $logger,
        private SettingService       $settingService,
        private \App\Services\Gamification\XpService $xpService,
        EventDispatcher             $eventDispatcher
    ) {
        parent::__construct($logger, null, $db, null, null, null, null, $eventDispatcher);
        try {
            $this->outboxService = container()->get(\App\Services\OutboxService::class);
        } catch (\Throwable $e) {
            $this->outboxService = null;
        }
    }

    public function deleteOldSessions(int $days = 7): int
    {
        return $this->securityModel->expireOldSessions($days);
    }

    public function deleteOldActivityLogs(int $days = 90): int
    {
        return $this->logModel->softDeleteOlderThan($days);
    }

    public function deleteExpiredPasswordResets(): int
    {
        return $this->securityModel->deleteExpiredPasswordResets();
    }

    public function deleteOldSentEmails(int $days = 30): int
    {
        return $this->emailQueueModel->deleteOldSent($days);
    }

    public function expireOldAdvertisements(): int
    {
        return $this->adModel->expireOldAdvertisements();
    }

    public function getPendingCryptoDeposits(int $hours = 12, int $limit = 10): array
    {
        return $this->cryptoDepositModel->getPendingRecent($hours, $limit);
    }

    public function getExpiredCryptoDeposits(int $minutes = 30, int $minAttempts = 3): array
    {
        return $this->cryptoDepositModel->getExpiredPending($minutes, $minAttempts);
    }

    public function moveCryptoDepositToManualReview(int $depositId, string $reason): bool
    {
        return $this->cryptoDepositModel->updateStatus($depositId, 'manual_review', null, $reason);
    }

    public function getStuckTransactions(int $hours = 1): array
    {
        return $this->transactionModel->getStuckTransactions($hours);
    }

    public function markTransactionFailed(int $transactionId): bool
    {
        return $this->transactionModel->updateStatus(
            $transactionId,
            'failed',
            ['reason' => 'Transaction timeout - auto-failed by system']
        );
    }

    public function getOldRejectedKycRecords(int $days = 60): array
    {
        return $this->kycModel->getOldRejected($days);
    }

    public function markKycDocumentsDeleted(int $kycId): bool
    {
        return $this->kycModel->update($kycId, ['documents_deleted' => 1]);
    }

    public function getUsersWithElevatedTier(array $tiers = ['gold', 'vip']): array
    {
        return $this->userModel->getByTierLevels($tiers);
    }

    public function countUserActiveDaysThisMonth(int $userId): int
    {
        return $this->logModel->countActiveDays($userId, date('Y-m-01'));
    }

    public function resetUserTierToSilver(int $userId): bool
    {
        return $this->userModel->update($userId, [
            'tier_level'       => 'silver',
            'tier_points'      => 0,
            'tier_expires_at'  => null,
        ]);
    }

    public function logSystemActivity(int $userId, string $action, string $description): void
    {
        $this->logModel->log([
            'user_id'     => $userId,
            'action'      => $action,
            'description' => $description,
            'ip_address'  => '127.0.0.1',
        ]);
    }

    public function countNewUsers(int $days = 7): int
    {
        return $this->userModel->countCreatedSince($days);
    }

    public function getTransactionVolume(int $days = 7): float
    {
        return $this->transactionModel->getCompletedVolumeSince($days);
    }

    public function getDailyTransactionReport(string $currency, string $date): object
    {
        return $this->transactionModel->getDailyReport($currency, $date);
    }

    public function expireCustomTaskSubmissions(): array
    {
        $expired = $this->taskSubmissionModel->submission_getExpiredSubmissions();
        $count = 0;

        foreach ($expired as $item) {
            try {
                $this->db->beginTransaction();
                $this->taskSubmissionModel->submission_markExpired($item->id);
                $this->adModel->decrementPendingCount($item->task_id);
                $this->db->commit();
                $count++;
                $this->logger->info('Submission expired by cron', ['submission_id' => $item->id, 'task_id' => $item->task_id]);
            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->logger->error('submission.expire.failed', ['error' => $e->getMessage()]);
            }
        }

        return ['success' => true, 'expired_count' => $count, 'message' => "{$count} submission منقضی شد."];
    }

    public function autoApproveCustomTaskSubmissions(): array
    {
        $autoApproveHours = (int) $this->settingService->get('custom_task_auto_approve_hours', 48);
        $unreviewed = $this->taskSubmissionModel->submission_getUnreviewedSubmissions($autoApproveHours);
        $count = 0;

        foreach ($unreviewed as $item) {
            try {
                $submission = $this->taskSubmissionModel->submission_findWithTask($item->id);
                if (!$submission) continue;

                $this->db->beginTransaction();
                $this->taskSubmissionModel->submission_markApproved($submission->id);

                $idempotencyKey = "ctask_auto_reward_{$submission->id}";
                $payload = [
                    'user_id' => $submission->worker_id,
                    'amount' => $submission->reward_amount,
                    'currency' => $submission->reward_currency,
                    'metadata' => [
                        'type' => 'task_reward',
                        'description' => "پاداش وظیفه #{$submission->task_id} (تایید خودکار)",
                        'idempotency_key' => $idempotencyKey,
                        'submission_id' => $submission->id,
                    ],
                ];

                if (isset($this->outboxService) && $this->outboxService) {
                    $ok = $this->outboxService->record('custom_task_submission', (int)$submission->id, 'wallet.deposit.requested', $payload);
                    if (!$ok) {
                        $this->db->rollBack();
                        $this->logger->error('submission.auto_approve.outbox_failed', ['submission_id' => $submission->id]);
                        continue;
                    }
                } else {
                    $this->walletService->deposit(
                        $submission->worker_id,
                        $submission->reward_amount,
                        $submission->reward_currency,
                        [
                            'type' => 'task_reward',
                            'description' => "پاداش وظیفه #{$submission->task_id} (تایید خودکار)",
                            'idempotency_key' => $idempotencyKey,
                        ]
                    );
                }

                $this->taskSubmissionModel->submission_markRewardPaid($submission->id);
                $this->adModel->incrementCustomTaskCompletion($submission->task_id, (float)$submission->reward_amount);

                $this->db->commit();
                $count++;
                $this->logger->info('Submission auto-approved by cron', ['submission_id' => $submission->id]);
            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->logger->error('submission.auto_approve.failed', ['error' => $e->getMessage()]);
            }
        }

        return ['success' => true, 'approved_count' => $count, 'message' => "{$count} submission به صورت خودکار تایید شد."];
    }

    public function completeFullCustomTasks(): array
    {
        $tasks = $this->db->fetchAll("
            SELECT id, user_id as creator_id, total_budget, remaining_budget, currency 
            FROM ads 
            WHERE type = 'custom_task' AND status = 'active' AND (remaining_budget <= 0 OR (total_count > 0 AND completed_count >= total_count))
        ");
        $count = 0;

        foreach ($tasks as $task) {
            try {
                $this->db->beginTransaction();
                $this->db->query("UPDATE ads SET status = 'completed', updated_at = NOW() WHERE id = ?", [$task->id]);
                
                $remaining = (float)($task->remaining_budget ?? 0);
                if ($remaining > 0) {
                    $this->eventDispatcher->dispatchAsync('wallet.deposit.requested', [
                        'user_id' => $task->creator_id,
                        'amount' => $remaining,
                        'currency' => $task->currency,
                        'metadata' => [
                            'type' => 'task_budget_refund',
                            'description' => "بازگشت بودجه باقیمانده تسک #{$task->id}",
                            'task_id' => $task->id,
                            'idempotency_key' => "ctask_refund_complete_{$task->id}"
                        ]
                    ]);
                }
                $this->db->commit();
                $count++;
            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->logger->error('task.complete.failed', ['error' => $e->getMessage()]);
            }
        }

        return ['success' => true, 'completed_count' => $count, 'message' => "{$count} تسک به صورت خودکار تکمیل شد."];
    }

    /**
     * اجرای خودکار ریزش تصاعدی امتیاز عدم فعالیت (Inactivity Score Decay)
     */
    public function applyInactivityScoreDecay(): array
    {
        // دریافت همه کاربران فعال
        $users = $this->db->fetchAll("
            SELECT id, email, level_slug FROM users
            WHERE status = 'active' AND deleted_at IS NULL
        ");

        $processed = 0;

        foreach ($users as $userData) {
            $userId = (int)$userData->id;

            // پیدا کردن تاریخ آخرین فعالیت کاربر
            $lastActivityStr = $this->db->fetchColumn("
                SELECT MAX(created_at) FROM activity_logs
                WHERE user_id = ?
            ", [$userId]);

            if (!$lastActivityStr) {
                // اگر هیچ لاگی نبود، تاریخ ثبت‌نام را به عنوان آخرین فعالیت در نظر می‌گیریم
                $lastActivityStr = $this->db->fetchColumn("
                    SELECT created_at FROM users WHERE id = ?
                ", [$userId]);
            }

            if (!$lastActivityStr) {
                continue;
            }

            $lastActivity = new \DateTime($lastActivityStr);
            $now = new \DateTime();
            $interval = $now->diff($lastActivity);
            $inactiveDays = $interval->days;

            if ($inactiveDays >= 1) {
                $processed++;
                // ساخت مدل کاربر و ارسال به سرویس
                $user = new User($this->db); 
                $user = $user->find($userId); // فرض بر این است که متد find وجود دارد، در غیر این صورت فیلدها را مپ می‌کنیم
                
                if (!$user) {
                    // Fallback اگر find در مدل به شکل دیگری است
                    $user = (object)['id' => $userId, 'level_slug' => $userData->level_slug];
                }
                
                $this->xpService->applyDecay($user, $inactiveDays);
            }
        }

        return [
            'success' => true,
            'processed_users' => $processed,
            'message' => "بررسی ریزش امتیاز عدم فعالیت برای {$processed} کاربر انجام شد."
        ];
    }
}
