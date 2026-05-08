<?php

namespace App\Services;

use Core\Database;
use App\Models\CustomTaskModel;
use App\Models\CustomTaskTransaction;

use App\Contracts\LoggerInterface;
class CustomTaskSettlementService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        private WalletService $walletService,
        private CustomTaskModel $taskModel,
        private CustomTaskTransaction $txModel
    ) {}

    public function holdBudgetOnPublish(object $task, int $advertiserId): array
    {
        $key = "custom-task:publish:{$task->id}";
        if ($this->txModel->findByIdempotencyKey($key)) {
            return ['ok' => true, 'message' => 'قبلا انجام شده'];
        }

        $amount = (float)$task->reward_amount * (int)$task->worker_limit;
        $fee = (float)($task->platform_fee ?? 0);
        $total = $amount + $fee;

        $this->db->beginTransaction();
        try {
            $withdraw = $this->walletService->withdraw(
                $advertiserId,
                $total,
                'IRT',
                [
                    'type' => 'custom_task_hold',
                    'description' => "Budget hold for task #{$task->id}",
                    'idempotency_key' => $key,
                ]
            );

            if (!$withdraw) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'موجودی کیف پول کافی نیست.'];
            }

            $this->txModel->create([
                'task_id' => (int)$task->id,
                'submission_id' => null,
                'actor_id' => $advertiserId,
                'type' => 'hold',
                'amount' => $total,
                'currency' => 'IRT',
                'idempotency_key' => $key,
                'meta' => ['reward_total' => $amount, 'fee' => $fee],
            ]);

            $this->db->commit();
            return ['ok' => true, 'message' => 'بودجه با موفقیت قفل شد.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'message' => 'خطای سیستمی در قفل بودجه.'];
        }
    }

    public function releaseOnApprove(object $submission, object $task, int $advertiserId, int $executorId): array
    {
        $key = "custom-task:approve-submission:{$submission->id}";
        if ($this->txModel->findByIdempotencyKey($key)) {
            return ['ok' => true, 'message' => 'قبلا انجام شده'];
        }

        $reward = (float)$task->reward_amount;

        $this->db->beginTransaction();
        try {
            $deposit = $this->walletService->deposit(
                $executorId,
                $reward,
                'IRT',
                [
                    'type' => 'custom_task_reward',
                    'description' => "Reward for submission #{$submission->id}",
                    'idempotency_key' => $key,
                ]
            );

            if (!$deposit) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'خطا در واریز پاداش انجام‌دهنده.'];
            }

            $this->txModel->create([
                'task_id' => (int)$task->id,
                'submission_id' => (int)$submission->id,
                'actor_id' => $executorId,
                'type' => 'release',
                'amount' => $reward,
                'currency' => 'IRT',
                'idempotency_key' => $key,
                'meta' => ['advertiser_id' => $advertiserId],
            ]);

            $this->db->commit();
            return ['ok' => true, 'message' => 'تسویه تایید انجام شد.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'message' => 'خطای سیستمی در تسویه تایید.'];
        }
    }

    public function refundOnReject(object $submission, object $task, int $advertiserId): array
    {
        $key = "custom-task:reject-submission:{$submission->id}";
        if ($this->txModel->findByIdempotencyKey($key)) {
            return ['ok' => true, 'message' => 'قبلا انجام شده'];
        }

        $refund = (float)$task->reward_amount;

        $this->db->beginTransaction();
        try {
            $deposit = $this->walletService->deposit(
                $advertiserId,
                $refund,
                'IRT',
                [
                    'type' => 'custom_task_refund',
                    'description' => "Refund rejected submission #{$submission->id}",
                    'idempotency_key' => $key,
                ]
            );

            if (!$deposit) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'خطا در برگشت وجه به تبلیغ‌دهنده.'];
            }

            $this->txModel->create([
                'task_id' => (int)$task->id,
                'submission_id' => (int)$submission->id,
                'actor_id' => $advertiserId,
                'type' => 'refund',
                'amount' => $refund,
                'currency' => 'IRT',
                'idempotency_key' => $key,
                'meta' => [],
            ]);

            $this->db->commit();
            return ['ok' => true, 'message' => 'برگشت وجه انجام شد.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'message' => 'خطای سیستمی در برگشت وجه.'];
        }
    }

    public function refundRemainingOnCancel(object $task, int $advertiserId): array
    {
        $key = "custom-task:cancel:{$task->id}";
        if ($this->txModel->findByIdempotencyKey($key)) {
            return ['ok' => true, 'message' => 'قبلا انجام شده'];
        }

        $held = $this->txModel->sumByTaskAndType((int)$task->id, 'hold');
        $released = $this->txModel->sumByTaskAndType((int)$task->id, 'release');
        $refunded = $this->txModel->sumByTaskAndType((int)$task->id, 'refund');
        $remaining = max(0, $held - $released - $refunded);

        if ($remaining <= 0) {
            return ['ok' => true, 'message' => 'مانده قابل برگشت وجود ندارد.'];
        }

        $this->db->beginTransaction();
        try {
            $deposit = $this->walletService->deposit(
                $advertiserId,
                $remaining,
                'IRT',
                [
                    'type' => 'custom_task_cancel_refund',
                    'description' => "Remaining refund for canceled task #{$task->id}",
                    'idempotency_key' => $key,
                ]
            );

            if (!$deposit) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'خطا در برگشت مانده بودجه.'];
            }

            $this->txModel->create([
                'task_id' => (int)$task->id,
                'submission_id' => null,
                'actor_id' => $advertiserId,
                'type' => 'refund',
                'amount' => $remaining,
                'currency' => 'IRT',
                'idempotency_key' => $key,
                'meta' => ['reason' => 'task_cancel'],
            ]);

            $this->db->commit();
            return ['ok' => true, 'message' => 'مانده بودجه برگشت داده شد.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'message' => 'خطای سیستمی در برگشت مانده بودجه.'];
        }
    }
}
