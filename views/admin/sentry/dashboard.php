<?php
/**
 * 🎛️ Sentry Dashboard - صفحه اصلی داشبورد
 */

$pageTitle = 'مانیتورینگ سیستم - Sentry';
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }

        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }

        .dashboard-header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .dashboard-header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        /* Health Score */
        .health-score {
            background: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .health-score-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            margin: 20px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            position: relative;
        }

        .health-score-circle.excellent {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }

        .health-score-circle.good {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .health-score-circle.fair {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .health-score-circle.poor {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: white;
        }

        .health-score-circle.critical {
            background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
            color: white;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent-color);
        }

        .stat-card.errors { --accent-color: #f56565; }
        .stat-card.performance { --accent-color: #48bb78; }
        .stat-card.alerts { --accent-color: #ed8936; }
        .stat-card.transactions { --accent-color: #4299e1; }

        .stat-card h3 {
            font-size: 0.9rem;
            color: #718096;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .stat-change {
            font-size: 0.9rem;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }

        .stat-change.up {
            background: #fed7d7;
            color: #c53030;
        }

        .stat-change.down {
            background: #c6f6d5;
            color: #22543d;
        }

        .stat-change.stable {
            background: #e2e8f0;
            color: #4a5568;
        }

        /* Issues Table */
        .issues-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .issues-section h2 {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: #2d3748;
        }

        .issues-table {
            width: 100%;
            border-collapse: collapse;
        }

        .issues-table thead {
            background: #f7fafc;
        }

        .issues-table th {
            padding: 15px;
            text-align: right;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .issues-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .issues-table tbody tr:hover {
            background: #f7fafc;
            cursor: pointer;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge.critical { background: #fed7d7; color: #c53030; }
        .badge.error { background: #feebc8; color: #c05621; }
        .badge.warning { background: #fefcbf; color: #744210; }
        .badge.info { background: #bee3f8; color: #2c5282; }

        /* Charts Section */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .chart-card h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: #2d3748;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .action-btn {
            background: white;
            border: 2px solid #e2e8f0;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #2d3748;
            display: block;
        }

        .action-btn:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        }

        .action-btn .icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="dashboard-header">
        <h1>🛡️ مانیتورینگ سیستم</h1>
        <p>نظارت لحظه‌ای بر عملکرد و خطاهای سیستم</p>
    </div>

    <!-- Health Score -->
    <div class="health-score">
        <h2>وضعیت سلامت سیستم</h2>
        <div class="health-score-circle <?= strtolower($data['overview']['health_score']['status'] ?? 'good') ?>">
            <?= round($data['overview']['health_score']['score'] ?? 0) ?>
        </div>
        <h3>نمره: <?= $data['overview']['health_score']['grade'] ?? 'A' ?> (<?= $data['overview']['health_score']['status'] ?? 'عالی' ?>)</h3>
        <p style="color: #718096; margin-top: 10px;">
            بر اساس نرخ خطا، عملکرد، زمان پاسخ و uptime
        </p>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <!-- Total Errors -->
        <div class="stat-card errors">
            <h3>خطاها (امروز)</h3>
            <div class="stat-value"><?= number_format($data['overview']['summary']['today']['error_issues'] ?? 0) ?></div>
            <?php 
            $errorChange = $data['overview']['summary']['change']['error_issues'] ?? ['value' => 0, 'direction' => 'stable'];
            ?>
            <span class="stat-change <?= $errorChange['direction'] ?>">
                <?= $errorChange['direction'] === 'up' ? '↑' : ($errorChange['direction'] === 'down' ? '↓' : '→') ?>
                <?= $errorChange['value'] ?>%
            </span>
        </div>

        <!-- Performance -->
        <div class="stat-card performance">
            <h3>میانگین زمان پاسخ</h3>
            <div class="stat-value"><?= round($data['overview']['summary']['today']['avg_response_time'] ?? 0) ?> <small style="font-size: 1rem;">ms</small></div>
            <span class="stat-change stable">لحظه‌ای</span>
        </div>

        <!-- Transactions -->
        <div class="stat-card transactions">
            <h3>تراکنش‌ها (امروز)</h3>
            <div class="stat-value"><?= number_format($data['overview']['summary']['today']['transactions'] ?? 0) ?></div>
            <span class="stat-change stable">درخواست‌های HTTP</span>
        </div>

        <!-- Active Alerts -->
        <div class="stat-card alerts">
            <h3>هشدارهای فعال</h3>
            <div class="stat-value"><?= count($data['overview']['recent_events'] ?? []) > 10 ? '10+' : count($data['overview']['recent_events'] ?? []) ?></div>
            <span class="stat-change stable">نیاز به بررسی</span>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="/admin/sentry/issues" class="action-btn">
            <div class="icon">🐛</div>
            <div>مشاهده خطاها</div>
        </a>
        <a href="/admin/sentry/performance" class="action-btn">
            <div class="icon">🚀</div>
            <div>عملکرد سیستم</div>
        </a>
        <a href="/admin/sentry/analytics" class="action-btn">
            <div class="icon">📊</div>
            <div>تحلیل و آمار</div>
        </a>
        <a href="/admin/sentry/alerts" class="action-btn">
            <div class="icon">🔔</div>
            <div>مدیریت هشدارها</div>
        </a>
        <a href="/admin/sentry/audit" class="action-btn">
            <div class="icon">📋</div>
            <div>Audit Trail</div>
        </a>
    </div>

    <!-- Trending Issues -->
    <div class="issues-section">
        <h2>🔥 خطاهای پرتکرار</h2>
        <table class="issues-table">
            <thead>
                <tr>
                    <th>عنوان</th>
                    <th>سطح</th>
                    <th>تعداد رخداد</th>
                    <th>آخرین بار</th>
                    <th>محیط</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($data['overview']['trending_issues'] ?? [], 0, 10) as $issue): ?>
                <tr onclick="window.location.href='/admin/sentry/issues/<?= $issue->id ?>'">
                    <td>
                        <strong><?= e(substr($issue->title ?? '', 0, 80)) ?></strong>
                    </td>
                    <td>
                        <span class="badge <?= $issue->level ?>"><?= strtoupper($issue->level ?? 'error') ?></span>
                    </td>
                    <td>
                        <strong><?= number_format($issue->count ?? 0) ?></strong>
                        <?php if (($issue->events_24h ?? 0) > 0): ?>
                            <small style="color: #f56565;">(+<?= $issue->events_24h ?> امروز)</small>
                        <?php endif; ?>
                    </td>
                    <td><?= \App\Helpers\JalaliDate::ago($issue->last_seen ?? '') ?></td>
                    <td><small><?= $issue->environment ?? 'production' ?></small></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($data['overview']['trending_issues'])): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: #718096;">
                        ✅ خطایی یافت نشد! سیستم سالم است.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
        <div class="chart-card">
            <h3>📈 روند خطاها (7 روز اخیر)</h3>
            <canvas id="errorsChart" height="200"></canvas>
        </div>
        <div class="chart-card">
            <h3>⚡ عملکرد (24 ساعت اخیر)</h3>
            <canvas id="performanceChart" height="200"></canvas>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        // Errors Chart
        new Chart(document.getElementById('errorsChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($data['trends']['errors']['data'] ?? [], 'date')) ?>,
                datasets: [{
                    label: 'خطاها',
                    data: <?= json_encode(array_column($data['trends']['errors']['data'] ?? [], 'count')) ?>,
                    borderColor: '#f56565',
                    backgroundColor: 'rgba(245, 101, 101, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                }
            }
        });

        // Performance Chart
        new Chart(document.getElementById('performanceChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($data['trends']['performance']['data'] ?? [], 'hour')) ?>,
                datasets: [{
                    label: 'زمان پاسخ (ms)',
                    data: <?= json_encode(array_column($data['trends']['performance']['data'] ?? [], 'avg')) ?>,
                    borderColor: '#48bb78',
                    backgroundColor: 'rgba(72, 187, 120, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                }
            }
        });
    </script>

</body>
</html>
