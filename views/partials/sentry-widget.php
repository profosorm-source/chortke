<?php
/**
 * 🛡️ Sentry Widget - نمایش در داشبورد اصلی
 * 
 * این فایل باید در views/admin/dashboard.php اضافه بشه
 */

use App\Services\Sentry\Analytics\DashboardService;
use Core\Database;

try {
    $db = Database::getInstance();
    $sentryDashboard = new DashboardService($db);
    $sentryHealth = $sentryDashboard->calculateHealthScore();
    $sentryStats = $sentryDashboard->getErrorStatistics();
} catch (\Throwable $e) {
    $sentryHealth = ['score' => 0, 'grade' => 'F', 'status' => 'unknown'];
    $sentryStats = ['total_issues' => 0, 'total_events' => 0];
}
?>

<div class="sentry-widget" style="
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 20px;
">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0; font-size: 1.1rem; color: #2d3748;">
            🛡️ سلامت سیستم
        </h3>
        <a href="/admin/sentry" style="
            color: #667eea;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        ">مشاهده کامل →</a>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; align-items: center;">
        <!-- Health Score Circle -->
        <div style="text-align: center;">
            <div style="
                width: 100px;
                height: 100px;
                border-radius: 50%;
                margin: 0 auto;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2rem;
                font-weight: bold;
                color: white;
                background: <?php
                    $score = $sentryHealth['score'] ?? 0;
                    if ($score >= 90) echo 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)';
                    elseif ($score >= 80) echo 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                    elseif ($score >= 70) echo 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)';
                    elseif ($score >= 60) echo 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)';
                    else echo 'linear-gradient(135deg, #eb3349 0%, #f45c43 100%)';
                ?>;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            ">
                <?= round($sentryHealth['score'] ?? 0) ?>
            </div>
            <div style="margin-top: 8px; font-size: 0.85rem; color: #718096;">
                نمره: <?= $sentryHealth['grade'] ?? 'N/A' ?>
            </div>
        </div>

        <!-- Stats -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div style="
                background: #fff5f5;
                border-radius: 8px;
                padding: 12px;
                text-align: center;
            ">
                <div style="font-size: 1.5rem; font-weight: bold; color: #c53030;">
                    <?= number_format($sentryStats['total_issues'] ?? 0) ?>
                </div>
                <div style="font-size: 0.75rem; color: #718096; margin-top: 4px;">
                    خطاهای فعال
                </div>
            </div>

            <div style="
                background: #f0fff4;
                border-radius: 8px;
                padding: 12px;
                text-align: center;
            ">
                <div style="font-size: 1.5rem; font-weight: bold; color: #22543d;">
                    <?= number_format($sentryStats['total_events'] ?? 0) ?>
                </div>
                <div style="font-size: 0.75rem; color: #718096; margin-top: 4px;">
                    رویداد (24 ساعت)
                </div>
            </div>

            <div style="
                background: #fffaf0;
                border-radius: 8px;
                padding: 12px;
                text-align: center;
                grid-column: span 2;
            ">
                <div style="font-size: 0.85rem; color: #744210;">
                    <?php
                    $critical = $sentryStats['by_level']['critical']['events'] ?? 0;
                    $error = $sentryStats['by_level']['error']['events'] ?? 0;
                    $warning = $sentryStats['by_level']['warning']['events'] ?? 0;
                    ?>
                    <span style="color: #c53030; font-weight: 600;">🔴 <?= $critical ?> Critical</span> | 
                    <span style="color: #c05621; font-weight: 600;">🟠 <?= $error ?> Error</span> | 
                    <span style="color: #744210; font-weight: 600;">🟡 <?= $warning ?> Warning</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div style="
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    ">
        <a href="/admin/sentry/issues" style="
            flex: 1;
            min-width: 120px;
            padding: 8px 12px;
            background: #f7fafc;
            border-radius: 6px;
            text-decoration: none;
            color: #4a5568;
            font-size: 0.85rem;
            text-align: center;
            transition: all 0.2s;
        " onmouseover="this.style.background='#edf2f7'" onmouseout="this.style.background='#f7fafc'">
            🐛 خطاها
        </a>
        <a href="/admin/sentry/performance" style="
            flex: 1;
            min-width: 120px;
            padding: 8px 12px;
            background: #f7fafc;
            border-radius: 6px;
            text-decoration: none;
            color: #4a5568;
            font-size: 0.85rem;
            text-align: center;
            transition: all 0.2s;
        " onmouseover="this.style.background='#edf2f7'" onmouseout="this.style.background='#f7fafc'">
            🚀 عملکرد
        </a>
        <a href="/admin/sentry/alerts" style="
            flex: 1;
            min-width: 120px;
            padding: 8px 12px;
            background: #f7fafc;
            border-radius: 6px;
            text-decoration: none;
            color: #4a5568;
            font-size: 0.85rem;
            text-align: center;
            transition: all 0.2s;
        " onmouseover="this.style.background='#edf2f7'" onmouseout="this.style.background='#f7fafc'">
            🔔 هشدارها
        </a>
    </div>
</div>

<style>
    @media (max-width: 768px) {
        .sentry-widget > div:first-of-type {
            grid-template-columns: 1fr !important;
        }
    }
</style>
