<?php

namespace App\Controllers\User;

use App\Services\User\UserDashboardService;

/**
 * DashboardController
 *
 * وابستگی‌ها از طریق constructor injection (Container auto-wire):
 *   UserDashboardService → inject می‌شود
 *   BaseUserController   → parent::__construct(null, null, null, null, $logger) از Container می‌گیرد
 */
class DashboardController extends BaseUserController
{
    private UserDashboardService $dashboardService;

    public function __construct(UserDashboardService $dashboardService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->dashboardService = $dashboardService;
    }

   public function index(): void
{
    $userId = $this->userId();
    if (!$userId) {
        $this->response->redirect(url('/login'));
        return;
    }

    // داده پیش‌فرض برای جلوگیری از Undefined key/property
    $data = [
        'wallet' => (object)[
            'balance_irt' => 0,
            'balance_usdt' => 0,
            'locked_irt' => 0,
        ],
        'tasks' => (object)[
            'completed' => 0,
            'pending' => 0,
            'rejected' => 0,
            'total' => 0,
            'earned' => 0,
        ],
        'transactions' => (object)[
            'total_deposits_irt' => 0,
            'total_withdraws_irt' => 0,
            'pending_count' => 0,
            'recent' => [],
        ],
        'campaigns' => (object)[
            'total' => 0,
            'recent' => [],
        ],
        'level' => (object)[
            'name' => 'SILVER',
            'slug' => 'silver',
            'progress' => 0,
            'is_max' => false,
            'current' => null,
            'next' => null,
            'details' => [],
        ],
        'referral' => (object)[
            'referred_count' => 0,
            'total_earned_irt' => 0,
            'pending_irt' => 0,
            'paid_count' => 0,
        ],
        'notifications' => (object)[
            'unread_count' => 0,
            'latest' => [],
        ],
        'charts' => (object)[
            'earnings' => ['labels' => [], 'values' => []],
            'platforms' => ['labels' => [], 'values' => []],
        ],
    ];

    try {
        $dashboardData = $this->dashboardService->getFullDashboardData($userId);
        $stats = $dashboardData['stats'] ?? [];
        $recentTaskExecutions = $dashboardData['recent_executions'] ?? [];
        $openTicketCount = $dashboardData['ticket_count'] ?? 0;

        if (!empty($stats)) {
            // ... rest of data mapping logic ...
            // (I'll simplify the mapping here or keep it robust)
            if (isset($stats['wallet'])) {
                $data['wallet'] = is_object($stats['wallet']) ? $stats['wallet'] : (object)$stats['wallet'];
            }

            // Existing mapping for stats
            if (isset($stats['tasks'])) {
                $data['tasks'] = is_object($stats['tasks']) ? $stats['tasks'] : (object)$stats['tasks'];
            }

            if (isset($stats['transactions'])) {
                $data['transactions'] = is_object($stats['transactions']) ? $stats['transactions'] : (object)$stats['transactions'];
            } else {
                $data['transactions'] = (object)[
                    'total_deposits_irt' => (float)($stats['today_deposit'] ?? 0),
                    'total_withdraws_irt' => (float)($stats['today_withdraw'] ?? 0),
                    'pending_count' => (int)($stats['pending_tx'] ?? 0),
                    'recent' => $stats['last_transactions'] ?? [],
                ];
            }
            
            // Map other fields from stats if present
            foreach (['campaigns', 'level', 'referral', 'notifications', 'charts'] as $key) {
                if (isset($stats[$key])) {
                    $data[$key] = is_object($stats[$key]) ? $stats[$key] : (object)$stats[$key];
                }
            }
        }
    } catch (\Throwable $e) {
        $this->logger->error('dashboard.data.load.failed', ['error' => $e->getMessage()]);
    }

    $wallet = $data['wallet'];
    $tasks = $data['tasks'];
    $transactions = $data['transactions'];
    $campaigns = $data['campaigns'];
    $level = $data['level'];
    $referral = $data['referral'];
    $notifications = $data['notifications'];
    $charts = $data['charts'];

    view('user/dashboard', [
        'title' => 'داشبورد',

        'walletBalance' => $wallet->balance_irt ?? 0,
        'walletBalanceUsdt' => $wallet->balance_usdt ?? 0,
        'lockedBalance' => $wallet->locked_irt ?? 0,

        'tasksCompleted' => $tasks->completed ?? 0,
        'tasksPending' => $tasks->pending ?? 0,
        'tasksRejected' => $tasks->rejected ?? 0,
        'tasksTotal' => $tasks->total ?? 0,
        'tasksEarned' => $tasks->earned ?? 0,

        'totalDeposits' => $transactions->total_deposits_irt ?? 0,
        'totalWithdraws' => $transactions->total_withdraws_irt ?? 0,
        'pendingTxCount' => $transactions->pending_count ?? 0,
        'recentTransactions' => $transactions->recent ?? [],

        'activeCampaigns' => $campaigns->total ?? 0,
        'recentAds' => $campaigns->recent ?? [],

        'currentLevel' => $level->name ?? 'SILVER',
        'levelSlug' => $level->slug ?? 'silver',
        'levelProgress' => $level->progress ?? 0,
        'levelIsMax' => $level->is_max ?? false,
        'levelCurrent' => $level->current ?? null,
        'levelNext' => $level->next ?? null,
        'levelDetails' => $level->details ?? [],

        'referralCount' => $referral->referred_count ?? 0,
        'referralEarnings' => $referral->total_earned_irt ?? 0,
        'referralPending' => $referral->pending_irt ?? 0,

        'notifCount' => $notifications->unread_count ?? 0,
        'topNotifications' => $notifications->latest ?? [],

        'chartLabels' => $charts->earnings['labels'] ?? [],
        'chartData' => $charts->earnings['values'] ?? [],
        'platformLabels' => $charts->platforms['labels'] ?? [],
        'platformData' => $charts->platforms['values'] ?? [],

        'totalEarnings' => $tasks->earned ?? 0,
        'recentTaskExecutions' => $recentTaskExecutions,
        'openTicketCount' => $openTicketCount,
    ]);
}
}
