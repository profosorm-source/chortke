<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\CustomTaskModel;
use App\Contracts\LoggerInterface;


class AnalyticsService
{

    /**
 * AnalyticsService - Orchestrator
 * خدمات تحلیل و گزارش‌گیری
 * Consolidated from: AnalyticsService, KpiService, CustomTaskAnalyticsService, ReportService
 */
    private \App\Contracts\LoggerInterface $logger;
    private AnalyticsQueryService $repository;
    private AnalyticsExporter $exporter;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        AnalyticsQueryService $repository,
        AnalyticsExporter $exporter
    ) {        $this->logger = $logger;
        $this->repository = $repository;
        $this->exporter = $exporter;

            }

    // ==========================================
    //  Metrics - داشبورد جامع
    // ==========================================

    /**
     * دریافت آمار کاربران
     */
    public function getUserMetrics(): array
    {
        return $this->repository->getUserStats();
    }

    /**
     * دریافت آمار مالی
     */
    public function getTransactionMetrics(?string $currency = null): array
    {
        return $this->repository->getFinancialStats($currency);
    }

    /**
     * دریافت آمار تسک‌ها
     */
    public function getTaskMetrics(): array
    {
        return $this->repository->getTaskStats();
    }

    /**
     * دریافت آمار تسک‌های سفارشی (Custom Tasks)
     * from CustomTaskAnalyticsService
     */
    public function getCustomTaskMetrics(int $taskId, int $days = 30): array
    {
        return $this->repository->getCustomTaskStats($taskId, $days);
    }

    /**
     * دریافت KPI‌های کسب‌و‌کار
     * from KpiService
     */
    public function getKpis(): array
    {
        return [
            'users' => $this->getUserMetrics(),
            'transactions' => $this->getTransactionMetrics(),
            'tasks' => $this->getTaskMetrics(),
        ];
    }

    public function getTicketStats(): array
    {
        return $this->repository->getTicketStats();
    }

    public function getFraudStats(): array
    {
        return $this->repository->getFraudStats();
    }

    public function getChurnRate(): float
    {
        return $this->repository->getChurnRate();
    }

    public function getConversionRate(): float
    {
        return $this->repository->getConversionRate();
    }

    public function getTasksByPlatform(): array
    {
        return $this->repository->getTasksByPlatform();
    }

    public function getHourlyActivity(int $days = 30): array
    {
        return $this->repository->getHourlyActivity($days);
    }

    public function getInvestmentStats(): array
    {
        return $this->repository->getInvestmentStats();
    }

    public function getReferralStats(): array
    {
        return $this->repository->getReferralStats();
    }

    public function getTopUsers(int $limit = 20): array
    {
        return $this->repository->getTopUsers($limit);
    }

    public function getLotteryStats(): array
    {
        return $this->repository->getLotteryStats();
    }

    public function getDashboardSummary(): array
    {
        return $this->repository->getDashboardSummary();
    }

    // ==========================================
    //  Dashboard Analytics
    // ==========================================

    /**
     * دریافت داشبورد جامع ادمین
     */
    public function getAdminDashboard(): array
    {
        return [
            'users' => $this->getUserMetrics(),
            'transactions' => $this->getTransactionMetrics(),
            'tasks' => $this->getTaskMetrics(),
            'daily_registrations' => $this->getDailyRegistrations(30),
            'daily_revenue' => $this->getDailyRevenue(30),
        ];
    }

    /**
     * دریافت داشبورد creator تسک‌های سفارشی
     */
    public function getCreatorDashboard(int $userId): array
    {
        return $this->repository->getCreatorDashboard($userId);
    }

    /**
     * دریافت داشبورد worker تسک‌های سفارشی
     */
    public function getWorkerDashboard(int $userId): array
    {
        return $this->repository->getWorkerDashboard($userId);
    }

    /**
     * دریافت تسک‌های محبوب
     */
    public function getTrendingTasks(int $limit = 10): array
    {
        return $this->repository->getTrendingTasks($limit);
    }

    // ==========================================
    //  Report Generation
    // ==========================================

    /**
     * تولید گزارش CSV
     */
    public function generateReport(string $format = 'csv', array $data = []): void
    {
        $reportData = $data ?: $this->prepareReportData();

        match ($format) {
            'csv' => $this->exporter->generateCSV($reportData),
            'excel' => $this->exporter->generateExcel($reportData),
            'pdf' => $this->exporter->generatePDF($reportData),
            default => $this->exporter->generateCSV($reportData),
        };
    }

    /**
     * تولید CSV
     */
    public function generateCSV(array $data = []): void
    {
        $this->generateReport('csv', $data);
    }

    /**
     * تولید Excel
     */
    public function generateExcel(array $data = []): void
    {
        $this->generateReport('excel', $data);
    }

    /**
     * تولید PDF
     */
    public function generatePDF(array $data = []): void
    {
        $this->generateReport('pdf', $data);
    }

    /**
     * آماده‌سازی داده‌های گزارش
     */
    private function prepareReportData(): array
    {
        return [
            'users' => $this->getUserMetrics(),
            'transactions' => $this->getTransactionMetrics(),
            'tasks' => $this->getTaskMetrics(),
        ];
    }

    // ==========================================
    //  Time-Series Data (Charts)
    // ==========================================

    /**
     * ثبت‌نام روزانه (برای نمودار)
     */
    public function getDailyRegistrations(int $days = 30): array
    {
        return $this->repository->getDailyRegistrations($days);
    }

    /**
     * درآمد روزانه (برای نمودار)
     */
    public function getDailyRevenue(int $days = 30, ?string $currency = null): array
    {
        return $this->repository->getDailyRevenue($days, $currency);
    }

    /**
     * واریز و برداشت روزانه
     */
    public function getDailyDepositsWithdrawals(int $days = 30, ?string $currency = null): array
    {
        return $this->repository->getDailyDepositsWithdrawals($days, $currency);
    }

    /**
     * تسک‌های تکمیل‌شده روزانه
     */
    public function getDailyCompletedTasks(int $days = 30): array
    {
        return $this->repository->getDailyCompletedTasks($days);
    }

    // ==========================================
    //  Cache Management
    // ==========================================

    /**
     * پاک کردن کش (هنگام ریفرش داده‌ها)
     */
    public function clearCache(int $taskId = null, int $userId = null): void
    {
        $this->repository->clearCache($taskId, $userId);
        $this->logger->info('Analytics cache cleared', [
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);
    }

    /**
     * ریکارد رویدادهای تحلیلی
     */
    public function recordEvent(string $eventType, array $data = []): void
    {
        $this->logger->info("Analytics event: {$eventType}", $data);
    }

    public function getSystemHealth(): array
    {
        return $this->repository->getSystemHealth();
    }

    /**
     * دریافت آمار کلی تسک‌ها جهت انطباق با نسخه‌های قدیمی
     */
    public function getStats(): array
    {
        return $this->getTaskMetrics();
    }
}
