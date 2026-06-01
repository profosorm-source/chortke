<?php

namespace App\Controllers\Admin;

use App\Services\Analytics\AnalyticsService;
use App\Services\ExportService;
use App\Controllers\Admin\BaseAdminController;

class KpiController extends BaseAdminController
{
    private ExportService $exportService;

    private AnalyticsService $analyticsService;

    public function __construct(
        \App\Services\ExportService $exportService,
        AnalyticsService $analyticsService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->exportService = $exportService;
        $this->analyticsService = $analyticsService;
    }

    /**
     * داشبورد KPI اصلی
     */
    public function index()
    {
        $userStats = $this->analyticsService->getUserMetrics();
        $financialStats = $this->analyticsService->getTransactionMetrics();
        $taskStats = $this->analyticsService->getTaskMetrics();
        $ticketStats = $this->analyticsService->getTicketStats();
        $fraudStats = $this->analyticsService->getFraudStats();
        $churnRate = $this->analyticsService->getChurnRate();
        $conversionRate = $this->analyticsService->getConversionRate();

        return view('admin.kpi.index', [
            'userStats' => $userStats,
            'financialStats' => $financialStats,
            'taskStats' => $taskStats,
            'ticketStats' => $ticketStats,
            'fraudStats' => $fraudStats,
            'churnRate' => $churnRate,
            'conversionRate' => $conversionRate,
        ]);
    }

    /**
     * داده‌های نمودار (AJAX)
     */
    public function chartData()
    {
                
        $type = $this->request->get('type') ?: 'revenue';
        $days = (int)($this->request->get('days') ?: 30);
        $days = \min($days, 365);

        $data = match ($type) {
            'revenue' => $this->analyticsService->getDailyRevenue($days),
            'registrations' => $this->analyticsService->getDailyRegistrations($days),
            'tasks' => $this->analyticsService->getDailyCompletedTasks($days),
            'deposits_withdrawals' => $this->analyticsService->getDailyDepositsWithdrawals($days),
            'platforms' => $this->analyticsService->getTasksByPlatform(),
            'hourly' => $this->analyticsService->getHourlyActivity(\min($days, 30)),
            default => [],
        };

        return $this->response->json(['success' => true, 'type' => $type, 'data' => $data]);
    }

    /**
     * جزئیات مالی
     */
    public function financial()
    {
        $financialStats = $this->analyticsService->getTransactionMetrics();
        $dailyRevenue = $this->analyticsService->getDailyRevenue(30);
        $dailyDW = $this->analyticsService->getDailyDepositsWithdrawals(30);
        $investmentStats = $this->analyticsService->getInvestmentStats();
        $referralStats = $this->analyticsService->getReferralStats();

        return view('admin.kpi.financial', [
            'financialStats' => $financialStats,
            'dailyRevenue' => $dailyRevenue,
            'dailyDW' => $dailyDW,
            'investmentStats' => $investmentStats,
            'referralStats' => $referralStats,
        ]);
    }

    /**
     * جزئیات کاربران
     */
    public function users()
    {
        $userStats = $this->analyticsService->getUserMetrics();
        $dailyReg = $this->analyticsService->getDailyRegistrations(30);
        $topUsers = $this->analyticsService->getTopUsers(20);
        $lotteryStats = $this->analyticsService->getLotteryStats();

        return view('admin.kpi.users', [
            'userStats' => $userStats,
            'dailyReg' => $dailyReg,
            'topUsers' => $topUsers,
            'lotteryStats' => $lotteryStats,
        ]);
    }

    /**
     * خروجی CSV کاربران
     */
    public function exportUsers()
    {
                $filters = [
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
        ];

        $data = $this->exportService->prepareUsersExport($filters);
        $this->exportService->exportCsv($data['headers'], $data['rows'], 'users_export');
    }

    /**
     * خروجی CSV تراکنش‌ها
     */
    public function exportTransactions()
    {
                $filters = [
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
            'type' => $this->request->get('type'),
            'status' => $this->request->get('status'),
        ];

        $data = $this->exportService->prepareTransactionsExport($filters);
        $this->exportService->exportCsv($data['headers'], $data['rows'], 'transactions_export');
    }

    /**
     * خروجی JSON خلاصه
     */
    public function exportSummary()
    {
        $summary = $this->analyticsService->getDashboardSummary();

        $this->exportService->exportJson($summary, 'kpi_summary');
    }
}