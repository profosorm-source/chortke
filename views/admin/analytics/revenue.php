<?php view('layouts.header', ['title' => $title]) ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= h($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics/revenue?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics/revenue?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">هفته</a>
                <a href="<?= url('/admin/analytics/revenue?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">ماه</a>
                <a href="<?= url('/admin/analytics/revenue?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">سال</a>
            </div>
        </div>
    </div>

    <!-- Revenue Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">درآمد کل</h6>
                    <h2 class="text-success"><?= number_format($metrics['revenue']['total']) ?> تومان</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">درآمد خالص</h6>
                    <h2 class="text-primary"><?= number_format($metrics['revenue']['net']) ?> تومان</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">هزینه‌ها</h6>
                    <h2 class="text-danger"><?= number_format($metrics['revenue']['costs']) ?> تومان</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">سود</h6>
                    <h2 class="text-warning"><?= number_format($metrics['revenue']['profit']) ?> تومان</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue Breakdown -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">تجزیه درآمد</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>درآمد از وظایف اجتماعی</td>
                            <td><strong class="text-primary"><?= number_format($metrics['breakdown']['social_tasks']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>درآمد از وظایف سفارشی</td>
                            <td><strong class="text-info"><?= number_format($metrics['breakdown']['custom_tasks']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>درآمد از تبلیغات</td>
                            <td><strong class="text-success"><?= number_format($metrics['breakdown']['ads']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>درآمد از اشتراک‌ها</td>
                            <td><strong class="text-warning"><?= number_format($metrics['breakdown']['subscriptions']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>درآمد از سایر منابع</td>
                            <td><strong class="text-muted"><?= number_format($metrics['breakdown']['other']) ?> تومان</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">تجزیه هزینه‌ها</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>پاداش کاربران</td>
                            <td><strong class="text-danger"><?= number_format($metrics['costs']['rewards']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>هزینه‌های سرور</td>
                            <td><strong class="text-danger"><?= number_format($metrics['costs']['server']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>هزینه‌های بازاریابی</td>
                            <td><strong class="text-danger"><?= number_format($metrics['costs']['marketing']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>هزینه‌های عملیاتی</td>
                            <td><strong class="text-danger"><?= number_format($metrics['costs']['operational']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>سایر هزینه‌ها</td>
                            <td><strong class="text-danger"><?= number_format($metrics['costs']['other']) ?> تومان</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Profit Margin -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">مارجین سود</h5>
                </div>
                <div class="card-body">
                    <?php
                    $totalRevenue = $metrics['revenue']['total'];
                    $totalCosts = $metrics['revenue']['costs'];
                    $profit = $metrics['revenue']['profit'];
                    $profitMargin = $totalRevenue > 0 ? ($profit / $totalRevenue) * 100 : 0;
                    ?>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center">
                                <h4 class="text-success">درآمد کل</h4>
                                <h3 class="text-success"><?= number_format($totalRevenue) ?> تومان</h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <h4 class="text-danger">هزینه‌ها</h4>
                                <h3 class="text-danger"><?= number_format($totalCosts) ?> تومان</h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <h4 class="text-warning">سود</h4>
                                <h3 class="text-warning"><?= number_format($profit) ?> تومان</h3>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <strong>مارجین سود:</strong>
                        <div class="progress" style="height: 30px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $profitMargin ?>%">
                                <?= round($profitMargin, 1) ?>%
                            </div>
                        </div>
                    </div>
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

<?php view('layouts.footer') ?>
