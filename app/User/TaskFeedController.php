<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\UnifiedTaskService;
use Core\Database;

/**
 * TaskFeedController - The Master Dashboard for Workers to Find and Filter Earning Tasks.
 */
class TaskFeedController extends BaseController
{
    public function __construct(
        private UnifiedTaskService $taskService,
        private Database $db
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

        // Statistics / User Status quick-glance cards
        $userStats = $this->db->fetch("
            SELECT 
                (SELECT COUNT(*) FROM social_task_executions WHERE executor_id = ? AND status = 'completed') as s_done,
                (SELECT COUNT(*) FROM seo_executions WHERE user_id = ? AND status = 'completed') as seo_done,
                (SELECT COUNT(*) FROM custom_task_submissions WHERE user_id = ? AND status = 'approved') as c_done
        ", [$userId, $userId, $userId]);

        $totalDone = (int)($userStats->s_done ?? 0) + (int)($userStats->seo_done ?? 0) + (int)($userStats->c_done ?? 0);

        return view('user.tasks.feed', [
            'tasks'       => $tasks,
            'totalTasks'  => $totalTasks,
            'totalPages'  => $totalPages,
            'currentPage' => $page,
            'filters'     => $filters,
            'platforms'   => $platforms,
            'totalDone'   => $totalDone
        ]);
    }
}
