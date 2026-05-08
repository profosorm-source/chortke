<?php $title = 'داشبورد KPI و آنالیتیکس'; $layout = 'admin'; ob_start(); ?>

<?php
$curr = $financialStats['currency'] ?? 'irt';
$currSymbol = $curr === 'usdt' ? 'USDT' : 'تومان';

function formatMoney($amount, $curr) {
    if ($curr === 'usdt') return number_format((float)$amount, 2) . ' USDT';
    return number_format((float)$amount) . ' تومان';
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><span class="material-icons me-1" style="vertical-align:middle;">analytics</span> داشبورد KPI</h4>
        <div class="btn-group btn-group-sm">
            <a href="<?= url('/admin/kpi/financial') ?>" class="btn btn-outline-primary">مالی</a>
            <a href="<?= url('/admin/kpi/users') ?>" class="btn btn-outline-primary">کاربران</a>
            <div class="dropdown">
                <button class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">خروجی</button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="<?= url('/admin/kpi/export/users') ?>">📄 کاربران (CSV)</a></li>
                    <li><a class="dropdown-item" href="<?= url('/admin/kpi/export/transactions') ?>">📄 تراکنش‌ها (CSV)</a></li>
                    <li><a class="dropdown-item" href="<?= url('/admin/kpi/export/summary') ?>">📋 خلاصه (JSON)</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ردیف 1: آمار اصلی -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background:linear-gradient(135deg,#4fc3f7,#29b6f6);"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon" style="background:rgba(79,195,247,0.1);color:#4fc3f7;"><span class="material-icons">people</span></div>
                    <div class="me-3">
                        <div class="stat-label">کل کاربران</div>
                        <div class="stat-value"><?= number_format($userStats['total']) ?></div>
                        <small class="text-success">+<?= e($userStats['new_today']) ?> امروز</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background:linear-gradient(135deg,#4caf50,#43a047);"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon" style="background:rgba(76,175,80,0.1);color:#4caf50;"><span class="material-icons">account_balance_wallet</span></div>
                    <div class="me-3">
                        <div class="stat-label">درآمد ماهانه</div>
                        <div class="stat-value" style="font-size:18px;"><?= formatMoney($financialStats['monthly_revenue'], $curr) ?></div>
                        <small class="text-muted">امروز: <?= formatMoney($financialStats['today_revenue'], $curr) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background:linear-gradient(135deg,#ffa726,#ff9800);"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon" style="background:rgba(255,167,38,0.1);color:#ffa726;"><span class="material-icons">task_alt</span></div>
                    <div class="me-3">
                        <div class="stat-label">تسک‌های تکمیل (ماهانه)</div>
                        <div class="stat-value"><?= number_format($taskStats['completed_month']) ?></div>
                        <small class="text-muted">امروز: <?= number_format($taskStats['completed_today']) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background:linear-gradient(135deg,#ab47bc,#9c27b0);"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon" style="background:rgba(171,71,188,0.1);color:#ab47bc;"><span class="material-icons">trending_up</span></div>
                    <div class="me-3">
                        <div class="stat-label">ARPU ماهانه</div>
                        <div class="stat-value" style="font-size:18px;"><?= formatMoney($financialStats['arpu'], $curr) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ردیف 2: KPI های کلیدی -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div class="text-primary" style="font-size:28px;font-weight:bold;"><?= e($userStats['dau']) ?></div>
                <small class="text-muted">DAU</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div class="text-info" style="font-size:28px;font-weight:bold;"><?= e($userStats['wau']) ?></div>
                <small class="text-muted">WAU</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div class="text-success" style="font-size:28px;font-weight:bold;"><?= e($userStats['mau']) ?></div>
                <small class="text-muted">MAU</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div style="font-size:28px;font-weight:bold;color:<?= $churnRate > 20 ? '#f44336' : ($churnRate > 10 ? '#ff9800' : '#4caf50') ?>;"><?= e($churnRate) ?>%</div>
                <small class="text-muted">Churn Rate</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div style="font-size:28px;font-weight:bold;color:<?= $conversionRate > 30 ? '#4caf50' : ($conversionRate > 15 ? '#ff9800' : '#f44336') ?>;"><?= e($conversionRate) ?>%</div>
                <small class="text-muted">Conversion</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div style="font-size:28px;font-weight:bold;color:<?= $fraudStats['suspicious_users'] > 10 ? '#f44336' : '#4caf50' ?>;"><?= e($fraudStats['suspicious_users']) ?></div>
                <small class="text-muted">مشکوک</small>
            </div>
        </div>
    </div>

    <!-- ردیف 3: نمودارها -->
    <div class="row mb-4">
        <div class="col-lg-8 mb-3">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">نمودار درآمد</h6>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary btn-chart-period active" data-days="7">هفته</button>
                        <button class="btn btn-outline-primary btn-chart-period" data-days="30">ماه</button>
                        <button class="btn btn-outline-primary btn-chart-period" data-days="90">سه‌ماه</button>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="revenueChart" height="280"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">تسک‌ها بر اساس پلتفرم</h6></div>
                <div class="card-body">
                    <canvas id="platformChart" height="280"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ردیف 4: ثبت‌نام + تسک -->
    <div class="row mb-4">
        <div class="col-lg-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">ثبت‌نام روزانه</h6></div>
                <div class="card-body">
                    <canvas id="registrationChart" height="220"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">تسک‌های تکمیل‌شده روزانه</h6></div>
                <div class="card-body">
                    <canvas id="taskChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ردیف 5: آمار سریع -->
    <div class="row">
        <!-- تیکت‌ها -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">پشتیبانی</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>تیکت باز:</span><strong class="text-danger"><?= e($ticketStats['open']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>در حال بررسی:</span><strong class="text-warning"><?= e($ticketStats['in_progress']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>کل تیکت‌ها:</span><strong><?= e($ticketStats['total']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>میانگین پاسخ:</span><strong><?= e($ticketStats['avg_response_hours']) ?> ساعت</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- مالی -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">مالی</h6></div>
                <div class="card-body" style="font-size:13px;">
                    <div class="d-flex justify-content-between mb-2">
                        <span>واریز امروز:</span><strong class="text-success"><?= formatMoney($financialStats['today_deposits'], $curr) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>برداشت امروز:</span><strong class="text-danger"><?= formatMoney($financialStats['today_withdrawals'], $curr) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>در انتظار:</span><strong class="text-warning"><?= e($financialStats['pending_transactions']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>گردش خالص:</span><strong><?= formatMoney($financialStats['net_flow'], $curr) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- ضد تقلب -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">ضد تقلب</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>کاربران مشکوک:</span><strong class="text-danger"><?= e($fraudStats['suspicious_users']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>بن امروز:</span><strong><?= e($fraudStats['blocked_today']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>لیست سیاه:</span><strong><?= e($fraudStats['silent_blacklisted']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>تقلب تسک (ماه):</span><strong><?= e($fraudStats['fraud_tasks_month']) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- سطح کاربران -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">سطح‌بندی</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>🥈 Silver:</span><strong><?= number_format($userStats['tiers']['silver'] ?? 0) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>🥇 Gold:</span><strong><?= number_format($userStats['tiers']['gold'] ?? 0) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>💎 VIP:</span><strong><?= number_format($userStats['tiers']['vip'] ?? 0) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>KYC تأیید:</span><strong class="text-success"><?= number_format($userStats['kyc_verified']) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var chartBaseUrl = '<?= url('/admin/kpi/chart-data') ?>';
    var revenueChartInstance = null;
    var registrationChartInstance = null;
    var taskChartInstance = null;
    var platformChartInstance = null;

    function loadChart(type, days, callback) {
        fetch(chartBaseUrl + '?type=' + type + '&days=' + days)
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) callback(res.data);
        });
    }

    // نمودار درآمد
    function renderRevenueChart(days) {
        loadChart('revenue', days, function(data) {
            var labels = data.map(function(d) { return d.date; });
            var values = data.map(function(d) { return parseFloat(d.total); });

            if (revenueChartInstance) revenueChartInstance.destroy();
            revenueChartInstance = new Chart(document.getElementById('revenueChart'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'درآمد',
                        data: values,
                        borderColor: '#4fc3f7',
                        backgroundColor: 'rgba(79,195,247,0.1)',
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointBackgroundColor: '#4fc3f7'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: function(v) { return v.toLocaleString(); } } }
                    }
                }
            });
        });
    }

    // نمودار ثبت‌نام
    loadChart('registrations', 30, function(data) {
        registrationChartInstance = new Chart(document.getElementById('registrationChart'), {
            type: 'bar',
            data: {
                labels: data.map(function(d) { return d.date; }),
                datasets: [{
                    label: 'ثبت‌نام',
                    data: data.map(function(d) { return d.count; }),
                    backgroundColor: 'rgba(76,175,80,0.6)',
                    borderColor: '#4caf50',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    });

    // نمودار تسک
    loadChart('tasks', 30, function(data) {
        taskChartInstance = new Chart(document.getElementById('taskChart'), {
            type: 'bar',
            data: {
                labels: data.map(function(d) { return d.date; }),
                datasets: [{
                    label: 'تسک تکمیل‌شده',
                    data: data.map(function(d) { return d.count; }),
                    backgroundColor: 'rgba(255,167,38,0.6)',
                    borderColor: '#ffa726',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    });

    // نمودار پلتفرم
    loadChart('platforms', 30, function(data) {
        var colors = {
            instagram: '#e91e63', youtube: '#f44336', telegram: '#2196f3',
            tiktok: '#000', twitter: '#1da1f2', google: '#4caf50'
        };
        var bgColors = data.map(function(d) {
            var p = (typeof d === 'object' && d !== null) ? (d.platform || 'other') : 'other';
            return colors[p] || '#9e9e9e';
        });

        platformChartInstance = new Chart(document.getElementById('platformChart'), {
            type: 'doughnut',
            data: {
                labels: data.map(function(d) { return d.platform || d[0] || '?'; }),
                datasets: [{
                    data: data.map(function(d) { return parseInt(d.count || d[1] || 0); }),
                    backgroundColor: bgColors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10 } }
                }
            }
        });
    });

    // دکمه‌های دوره زمانی
    document.querySelectorAll('.btn-chart-period').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.btn-chart-period').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            renderRevenueChart(parseInt(this.dataset.days));
        });
    });

    // بارگذاری اولیه
    renderRevenueChart(30);
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>