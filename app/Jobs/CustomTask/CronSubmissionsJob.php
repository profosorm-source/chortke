<?php

declare(strict_types=1);

namespace App\Jobs\CustomTask;

use App\Models\CustomTaskSubmissionModel;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Services\Settings\AppSettings;
use Core\Logger;
use Core\EventDispatcher;

class CronSubmissionsJob
{
    private CustomTaskSubmissionModel $submissionModel;
    private CustomTaskModerationService $moderationService;
    private AppSettings $appSettings;
    private Logger $logger;
    private EventDispatcher $eventDispatcher;
    public function __construct(
        CustomTaskSubmissionModel $submissionModel,
        CustomTaskModerationService $moderationService,
        AppSettings $appSettings,
        Logger $logger,
        EventDispatcher $eventDispatcher
    ) {        $this->submissionModel = $submissionModel;
        $this->moderationService = $moderationService;
        $this->appSettings = $appSettings;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
}

    public function autoApproveOldSubmissions(): int
    {
        $hours = (int) $this->appSettings->get('custom_task_auto_approve_hours', 48);
        $submissions = $this->submissionModel->getOldSubmissionsForAutoApproval($hours);

        $approved = 0;
        foreach ($submissions as $sub) {
            $result = $this->moderationService->approveSubmission($sub);
            if ($result['success']) {
                $approved++;

                $this->eventDispatcher->dispatchAsync('notification.requested', [
                    'user_id' => $sub->worker_id,
                    'type' => 'auto_approved',
                    'title' => 'مدرک شما به صورت خودکار تایید شد',
                    'message' => "مدرک شما برای وظیفه «{$sub->task_title}» به دلیل عدم بررسی توسط تبلیغ‌کننده، خودکار تایید و پاداش پرداخت شد.",
                    'data' => [
                        'submission_id' => $sub->id,
                        'task_id' => $sub->task_id
                    ]
                ]);
            }
        }

        return $approved;
    }
    public function expireOldSubmissions(): int
    {
        $expired = 0;
        $submissions = $this->submissionModel->submission_getExpiredSubmissions();

        foreach ($submissions as $sub) {
            try {
                $this->db->beginTransaction();

                $this->submissionModel->submission_update($sub->id, [
                    'status' => 'expired',
                ]);

                $this->taskModel->decrementPendingCount($sub->task_id);

                $this->db->commit();
                $expired++;

            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->logger->error('expire_submission_failed', [
                    'submission_id' => $sub->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $expired;
    }
}
