<?php view('layouts.header', ['title' => $title]) ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= h($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics/transactions?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics/transactions?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">هفته</a>
                <a href="<?= url('/admin/analytics/transactions?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">ماه</a>
                <a href="<?= url('/admin/analytics/transactions?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">سال</a>
            </div>
        </div>
    </div>

    <!-- Transaction Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">واریز‌ها</h6>
                    <h3 class="text-success"><?= number_format($metrics['deposits']['count']) ?></h3>
                    <small class="text-muted"><?= number_format($metrics['deposits']['amount']) ?> تومان</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">برداشت‌ها</h6>
                    <h3 class="text-danger"><?= number_format($metrics['withdrawals']['count']) ?></h3>
                    <small class="text-muted"><?= number_format($metrics['withdrawals']['amount']) ?> تومان</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">پرداخت‌ها</h6>
                    <h3 class="text-info"><?= number_format($metrics['payments']['count']) ?></h3>
                    <small class="text-muted"><?= number_format($metrics['payments']['amount']) ?> تومان</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">درآمد پلتفرم</h6>
                    <h3 class="text-warning"><?= number_format((int)$metrics['platform_fee']) ?></h3>
                    <small class="text-muted">تومان</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Net Flow -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">جریان خالص</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-6">
                            <h6 class="text-muted">جریان خالص</h6>
                            <h2 class="text-<?= $metrics['net_flow'] > 0 ? 'success' : 'danger' ?>">
                                <?= number_format((int)$metrics['net_flow']) ?> تومان
                            </h2>
                            <small class="text-muted">
                                (واریز - برداشت)
                            </small>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">نسبت برداشت به واریز</h6>
                            <h2 class="text-info">
                                <?php
                                $depositAmount = $metrics['deposits']['amount'];
                                $withdrawalAmount = $metrics['withdrawals']['amount'];
                                $ratio = $depositAmount > 0 ? ($withdrawalAmount / $depositAmount) * 100 : 0;
                                echo round($ratio, 1) . '%';
                                ?>
                            </h2>
                            <small class="text-muted">
                                از کل واریز‌ها
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction Volume Chart Placeholder -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">نمودار حجم تراکنش‌ها (۳۰ روز گذشته)</h5>
                </div>
                <div class="card-body">
                    <div id="transactionChart" style="height: 300px;">
                        <canvas id="transactionVolumeChart"></canvas>
                    </div>
                    <small class="text-muted">داده‌های نمودار از طریق API بارگذاری می‌شود</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Back Button -->
    <div class="row">
        <div class="col-md-12">
            <a href="<?= url('/admin/analytics') ?>" class="btn btn-outline-secondary">
                ← بازگشت به داشبورد
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Load chart data
    fetch('<?= url("/admin/analytics/chart-data?type=transactions") ?>')
        .then(response => response.json())
        .then(data => {
            const ctx = document.getElementById('transactionVolumeChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.data.map(item => item.date),
                    datasets: [{
                        label: 'واریز‌ها',
                        data: data.data.map(item => item.deposits),
                        borderColor: 'rgb(40, 167, 69)',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4
                    }, {
                        label: 'برداشت‌ها',
                        data: data.data.map(item => item.withdrawals),
                        borderColor: 'rgb(220, 53, 69)',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        })
        .catch(error => console.error('Error loading chart data:', error));
});
</script>

<?php view('layouts.footer') ?>
