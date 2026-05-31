<?php
declare(strict_types=1);

namespace App\Jobs\CustomTask;

use Core\Logger;
use Core\EventDispatcher;
use Core\RateLimiter;
use App\Models\CustomTaskSubmissionModel;
use App\Models\Ads;
use App\Services\Settings\AppSettings;

class SubmitProofJob
{
    public function __construct(
        private RateLimiter $rateLimiter,
        private CustomTaskSubmissionModel $submissionModel,
        private Ads $taskModel,
        private AppSettings $appSettings,
        private Logger $logger,
        private EventDispatcher $eventDispatcher
    ) {}

    public function handle(array $payload): array
    {
        $submissionId = (int)($payload["submission_id"] ?? 0);
        $workerId = (int)($payload["worker_id"] ?? 0);
        $proofData = $payload["proof_data"] ?? [];

        $proofData["task_execution_id"] = $submissionId;
        $request = new \App\Validators\Requests\SubmitCustomTaskProofRequest($proofData);
        $validated = $request->validateOrFail();

        if ($workerId <= 0) {
            return ["success" => false, "message" => "نقص در ارسال شناسه کارگر"];
        }

        if (!$this->rateLimiter->attempt("custom_task:submit:" . $workerId, 10, 10)) {
            $wait = ceil($this->rateLimiter->availableIn("custom_task:submit:" . $workerId) / 60);
            return ["success" => false, "message" => "تعداد درخواست‌های شما بیش از حد مجاز است. لطفا {$wait} دقیقه دیگر امتحان کنید."];
        }

        try {
            $submission = $this->submissionModel->submission_findById($submissionId);

            if (!$submission || (int)$submission->worker_id !== $workerId) {
                return ["success" => false, "message" => "دسترسی غیرمجاز."];
            }

            if ($submission->status !== "in_progress") {
                return ["success" => false, "message" => "وضعیت نامعتبر."];
            }

            if (strtotime((string)$submission->deadline_at) < time()) {
                return ["success" => false, "message" => "مهلت ارسال مدرک پایان یافته."];
            }

            if (!empty($proofData["proof_file_hash"])) {
                if ($this->submissionModel->submission_isDuplicateImage(
                    $proofData["proof_file_hash"],
                    (int)$submission->task_id
                )) {
                    return ["success" => false, "message" => "این مدرک قبلاً ارسال شده است."];
                }
            }

            $updateData = [
                "proof_text" => $proofData["proof_text"] ?? null,
                "proof_file" => $proofData["proof_file"] ?? null,
                "proof_file_hash" => $proofData["proof_file_hash"] ?? null,
                "submitted_at" => date("Y-m-d H:i:s"),
                "status" => "submitted",
            ];

            $this->submissionModel->submission_update($submissionId, $updateData);

            $this->eventDispatcher->dispatchAsync("custom_task.submission_created", [
                "submission_id" => $submissionId,
                "task_id" => $submission->task_id,
                "worker_id" => $workerId,
                "reward_amount" => $submission->reward_amount,
                "reward_currency" => $submission->reward_currency,
                "submitted_at" => date("Y-m-d H:i:s")
            ]);

            $this->logger->info("Proof submitted", [
                "submission_id" => $submissionId,
                "worker_id" => $workerId,
            ]);

            $task = $this->taskModel->find((int)$submission->task_id);
            $this->eventDispatcher->dispatchAsync("notification.requested", [
                "user_id" => $task->user_id,
                "type" => "task_proof_submitted",
                "title" => "مدرک جدید دریافت شد",
                "message" => "مدرک جدیدی برای وظیفه «{$task->title}» ارسال شد و منتظر بررسی است.",
                "data" => [
                    "task_id" => $task->id,
                    "submission_id" => $submissionId,
                    "url" => "/user/custom-tasks/submissions/{$submissionId}"
                ]
            ]);

            $autoApproveHours = (int) $this->appSettings->get("custom_task_auto_approve_hours", 48);
            
            return [
                "success" => true,
                "message" => "مدرک شما با موفقیت ارسال شد.",
                "auto_approve_info" => "در صورتی که کارفرما تا {$autoApproveHours} ساعت آینده بررسی نکند، بصورت خودکار تایید خواهد شد.",
            ];

        } catch (\Exception $e) {
            $this->logger->error("task.proof_submission.failed", [
                "channel" => "task",
                "error" => $e->getMessage(),
            ]);
            return ["success" => false, "message" => "خطا در ارسال مدرک."];
        }
    }
}
