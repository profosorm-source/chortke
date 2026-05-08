<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Advertisement;
use App\Models\CryptoDeposit;
use App\Models\CustomTaskModel;
use App\Models\EmailQueue;
use App\Models\KYCVerification;
use App\Models\SecurityModel;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WalletService;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;

/**
 * CronService — لایه Service برای عملیات Cron Jobs
 */
class CronService extends \App\Services\BaseService
{
    public function __construct(
        private Database             $db,
        private ActivityLog          $logModel,
        private Advertisement        $adModel,
        private CryptoDeposit        $cryptoDepositModel,
        private EmailQueue           $emailQueueModel,
        private KYCVerification      $kycModel,
        private SecurityModel        $securityModel,
        private Transaction          $transactionModel,
        private User                 $userModel,
        private CustomTaskModel       $taskModel,
        private WalletService        $walletService,
        protected LoggerInterface    $logger,
        private SettingService       $settingService
    ) {
        parent::__construct($logger);
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
        $expired = $this->taskModel->submission_getExpiredSubmissions();
        $count = 0;

        foreach ($expired as $item) {
            try {
                $this->db->beginTransaction();
                $this->taskModel->submission_markExpired($item->id);
                $this->taskModel->decrementPendingCount($item->task_id);
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
        $unreviewed = $this->taskModel->submission_getUnreviewedSubmissions($autoApproveHours);
        $count = 0;

        foreach ($unreviewed as $item) {
            try {
                $submission = $this->taskModel->submission_findWithTask($item->id);
                if (!$submission) continue;

                $this->db->beginTransaction();
                $this->taskModel->submission_markApproved($submission->id);

                $idempotencyKey = "ctask_auto_reward_{$submission->id}_" . time();
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

                $this->taskModel->submission_markRewardPaid($submission->id);
                $this->taskModel->incrementCompletedCountAndSpend($submission->task_id, $submission->reward_amount);

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
        $tasks = $this->taskModel->getFullyCompletedTasks();
        $count = 0;

        foreach ($tasks as $task) {
            try {
                $this->db->beginTransaction();
                $this->taskModel->markCompleted($task->id);
                $remaining = $task->total_budget - $task->spent_budget;
                if ($remaining > 0) {
                    $idempotencyKey = "ctask_refund_complete_{$task->id}_" . time();
                    $this->walletService->deposit($task->creator_id, $remaining, $task->currency, [
                        'type' => 'task_budget_refund',
                        'description' => "بازگشت بودجه باقیمانده تسک #{$task->id}",
                        'idempotency_key' => $idempotencyKey,
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
}
