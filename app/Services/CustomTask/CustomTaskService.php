<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Contracts\WalletServiceInterface;
use App\Services\Settings\AppSettings;

use Core\Database;
use App\Contracts\LoggerInterface;
use App\Exceptions\BusinessException;
use App\Validators\Requests\CreateCustomTaskRequest;

/**
 * CustomTaskService - Core custom tasks management
 */
class CustomTaskService
{

    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private \App\Models\CustomTaskAnalyticsModel $analyticsModel;
    private \App\Contracts\SearchServiceInterface $searchOrchestrator;
    private \App\Services\EscrowService $escrowService;
    private \App\Services\Interaction\RatingService $ratingService;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private LoggerInterface $logger;
    private WalletServiceInterface $walletService;
    private AppSettings $appSettings;
    private \Core\RateLimiter $rateLimiter;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        LoggerInterface $logger,
        WalletServiceInterface $walletService,
        AppSettings $appSettings,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        \App\Models\CustomTaskAnalyticsModel $analyticsModel,
        \App\Contracts\SearchServiceInterface $searchOrchestrator,
        \Core\RateLimiter $rateLimiter,
        \App\Services\EscrowService $escrowService,
        ?\App\Services\Interaction\RatingService $ratingService = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->walletService = $walletService;
        $this->appSettings = $appSettings;
        $this->rateLimiter = $rateLimiter;

        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->analyticsModel = $analyticsModel;
        $this->searchOrchestrator = $searchOrchestrator;
        $this->escrowService = $escrowService;
        $this->ratingService = $ratingService;
    }



    public function createTask(int $creatorId, array $data): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\CustomTask\CreateCustomTaskJob::class);
        return $job->handle(['creator_id' => $creatorId, 'data' => $data]);
    }

    public function find(int $id): ?object
    {
        return $this->taskModel->find($id);
    }

    public function getAvailableTasks(int $workerId, array $filters, int $limit, int $offset): array
    {
        return $this->taskModel->getAvailableCustomTasks($workerId, $filters, $limit, $offset);
    }

    public function getMyTasks(int $creatorId, ?string $status, int $limit, int $offset): array
    {
        return $this->taskModel->getByAdvertiser($creatorId, $limit, $offset, 'custom_task', $status);
    }

    public function getMySubmissions(int $workerId, ?string $status, int $limit, int $offset): array
    {
        return $this->submissionModel->submission_getByWorker($workerId, $status, $limit, $offset);
    }

    public function toggleFavorite(int $taskId, int $userId): array
    {
        try {
            $isCurrentlyFavorite = $this->taskModel->isTaskFavorited($taskId, $userId);

            $this->db->beginTransaction();

            $success = false;
            $message = '';
            $isFavorite = false;

            if ($isCurrentlyFavorite) {
                $success = $this->taskModel->removeFromFavorites($taskId, $userId);
                $message = 'از علاقه‌مندی‌ها حذف شد.';
                $isFavorite = false;
            } else {
                $success = $this->taskModel->addToFavorites($taskId, $userId);
                $message = 'به علاقه‌مندی‌ها اضافه شد.';
                $isFavorite = true;
            }

            if (!$success) {
                throw new \Exception('خطا در عملیات.');
            }

            $this->db->commit();
            return [
                'success' => true,
                'message' => $message,
                'is_favorite' => $isFavorite
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در عملیات.'];
        }
    }

    public function quickSearchAds(string $term, ?int $userId = null, int $limit = 5): array
    {
        return $this->searchOrchestrator->quickSearchAds($term, $userId, $limit);
    }

    public function quickSearchSubmissions(string $term, ?int $userId = null, int $limit = 5): array
    {
        return $this->searchOrchestrator->quickSearchSubmissions($term, $userId, $limit);
    }

    public function searchAdTasks(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchOrchestrator->searchAdTasks($q, $filters, $limit, $offset);
    }

    public function getSubmissionsByTask(int $taskId, ?string $status = null, int $limit = 30, int $offset = 0): array
    {
        return $this->submissionModel->submission_getByTask($taskId, $status, $limit, $offset);
    }

    public function getTaskAnalytics(int $taskId, int $days = 30): array
    {
        $analytics = $this->analyticsModel->getTaskAnalytics($taskId);

        $ratings = [];
        if ($this->ratingService) {
            $ratingsRaw = $this->ratingService->getRatingsByRef('custom_task', $taskId, 100);
            foreach ($ratingsRaw as $r) {
                $ratings[] = [
                    'rating' => $r['stars'] ?? $r['value'] ?? 0,
                    'created_at' => $r['created_at'],
                    'rater_name' => $r['rater_name'] ?? 'کاربر',
                ];
            }
        }

        return [
            'overall' => $analytics['overall'],
            'daily' => $analytics['daily'],
            'ratings' => $ratings,
        ];
    }
}
