<?php

declare(strict_types=1);

namespace App\Jobs\CustomTask;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Services\Interaction\RatingService;
use App\Services\Settings\AppSettings;
use Core\Database;
use Core\Logger;
use Core\EventDispatcher;

class RateSubmissionJob
{
    public function __construct(
        private Ads $taskModel,
        private CustomTaskSubmissionModel $submissionModel,
        private RatingService $ratingService,
        private AppSettings $appSettings,
        private Database $db,
        private Logger $logger,
        private EventDispatcher $eventDispatcher
    ) {}

    public function handle(int $submissionId, int $raterId, array $ratingData): array
    {
        if ($raterId <= 0) {
            return ['success' => false, 'message' => 'شناسه ارزیاب نامعتبر است'];
        }

        if (!$this->appSettings->get('custom_task_rating_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم امتیازدهی غیرفعال است.'];
        }

        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        if ($submission->status !== 'approved') {
            return ['success' => false, 'message' => 'فقط می‌توانید به submission های تایید شده امتیاز دهید.'];
        }

        $rating = (int) ($ratingData['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'message' => 'امتیاز باید بین 1 تا 5 باشد.'];
        }

        $reviewText = trim($ratingData['review_text'] ?? '');
        $minLength = (int) $this->appSettings->get('custom_task_min_rating_text_length', 20);
        
        if (!empty($reviewText) && mb_strlen($reviewText) < $minLength) {
            return ['success' => false, 'message' => "متن نظر باید حداقل {$minLength} کاراکتر باشد."];
        }

        $task = $this->taskModel->find($submission->task_id);
        
        if ($raterId == $task->user_id) {
            $ratingType = 'worker';
            $ratedUserId = $submission->worker_id;
        } elseif ($raterId == $submission->worker_id) {
            $ratingType = 'creator';
            $ratedUserId = $task->user_id;
        } else {
            return ['success' => false, 'message' => 'شما مجاز به امتیازدهی نیستید.'];
        }

        try {
            $this->db->beginTransaction();

            $success = $this->ratingService->rate(
                $raterId,
                'custom_task',
                $task->id,
                \App\Enums\ModuleContext::CUSTOM_TASKS,
                $rating
            );

            if (!$success) {
                throw new \Exception('خطا در ثبت امتیاز.');
            }

            $ratingObj = true;

            $this->db->commit();

            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => $ratedUserId,
                'type' => 'new_rating_received',
                'title' => 'امتیاز جدید دریافت کردید',
                'message' => "امتیاز {$rating} ستاره برای وظیفه «{$task->title}» دریافت کردید.",
                'data' => [
                    'task_id' => $task->id,
                    'rating' => $rating
                ]
            ]);

            return [
                'success' => true,
                'message' => 'امتیاز با موفقیت ثبت شد.',
                'rating' => $ratingObj
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('rating.create.failed', [
                'error' => $e->getMessage(),
                'submission_id' => $submissionId,
            ]);
            return ['success' => false, 'message' => 'خطا در ثبت امتیاز.'];
        }
    }
}
