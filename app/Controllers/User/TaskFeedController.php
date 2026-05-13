<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\UnifiedTaskService;

/**
 * TaskFeedController - The Master Dashboard for Workers to Find and Filter Earning Tasks.
 */
class TaskFeedController extends BaseController
{
    public function __construct(
        private UnifiedTaskService $taskService
    ) {
        parent::__construct();
    }

    /**
     * Display the Unified Dynamic Task Feed
     */
    public function index(): string
    {
        $userId = user_id();
        
        // Parse Filters from GET request
        $filters = [
            'type'      => $_GET['type'] ?? null,
            'platform'  => $_GET['platform'] ?? null,
            'min_price' => $_GET['min_price'] ?? null,
            'max_price' => $_GET['max_price'] ?? null,
            'q'         => $_GET['q'] ?? null,
            'sort'      => $_GET['sort'] ?? 'newest',
        ];

        // Setup Pagination
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        // Fetch Unified Data from Service
        $tasks      = $this->taskService->getTasksForExecutor($userId, $filters, $limit, $offset);
        $totalTasks = $this->taskService->countTasksForExecutor($userId, $filters);
        $totalPages = (int)ceil($totalTasks / $limit);

        // Helper Lookups for Filter Dropsdowns
        $platforms = $this->taskService->getAvailablePlatforms();

        // Get executor stats from service
        $stats = $this->taskService->getExecutorStats($userId);
        $userStats = (object)$stats;

        return view('user.tasks.feed', [
            'tasks'       => $tasks,
            'totalTasks'  => $totalTasks,
            'totalPages'  => $totalPages,
            'currentPage' => $page,
            'filters'     => $filters,
            'platforms'   => $platforms,
            'userStats'   => $userStats,
            'totalDone'   => $userStats->total_completed ?? 0
        ]);
    }
}
