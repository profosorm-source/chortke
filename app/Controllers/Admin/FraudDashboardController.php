<?php

namespace App\Controllers\Admin;

use App\Services\AntiFraud\FraudDashboardService;
use App\Controllers\Admin\BaseAdminController;

class FraudDashboardController extends BaseAdminController
{
    private FraudDashboardService $dashboardService;

    public function __construct(FraudDashboardService $dashboardService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->dashboardService = $dashboardService;
    }
    
    /**
     * داشبورد ضد تقلب
     */
    public function index()
    {
        $dashboard = $this->dashboardService->getCompleteDashboard();

        return view('admin/fraud/dashboard', [
            'stats' => $dashboard['overview'],
            'recentSuspicious' => $dashboard['recent_alerts'],
            'suspiciousIPs' => $dashboard['top_suspicious_ips'],
            'duplicateFingerprints' => $dashboard['top_suspicious_users'],
            'fraudTypeDistribution' => $dashboard['fraud_type_distribution'],
            'hourlyTrend' => $dashboard['hourly_trend'],
            'geographicThreats' => $dashboard['geographic_threats'],
            'rateLimitViolations' => $dashboard['rate_limit_violations'],
            'deviceStats' => $dashboard['device_stats'],
            'performanceMetrics' => $dashboard['performance_metrics'],
            'realtimeChart' => $dashboard['realtime_chart'],
            'generatedAt' => $dashboard['generated_at'],
        ]);
    }

    
    
    
}
