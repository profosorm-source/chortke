<?php view('layouts.header', ['title' => $title]) ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= h($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics/system-health?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics/system-health?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">هفته</a>
                <a href="<?= url('/admin/analytics/system-health?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">ماه</a>
                <a href="<?= url('/admin/analytics/system-health?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">سال</a>
            </div>
        </div>
    </div>

    <!-- System Health Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">میانگین پاسخ</h6>
                    <h2 class="text-primary"><?= number_format($metrics['performance']['avg_response_time'], 2) ?>ms</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">Uptime</h6>
                    <h2 class="text-success"><?= number_format($metrics['performance']['uptime'], 1) ?>%</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">خطاها</h6>
                    <h2 class="text-danger"><?= number_format($metrics['errors']['total']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">کاربران آنلاین</h6>
                    <h2 class="text-info"><?= number_format($metrics['users']['online']) ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Performance Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">متریک‌های عملکرد</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>میانگین زمان پاسخ</td>
                            <td><strong class="text-primary"><?= number_format($metrics['performance']['avg_response_time'], 2) ?>ms</strong></td>
                        </tr>
                        <tr>
                            <td>حداکثر زمان پاسخ</td>
                            <td><strong class="text-warning"><?= number_format($metrics['performance']['max_response_time'], 2) ?>ms</strong></td>
                        </tr>
                        <tr>
                            <td>کل درخواست‌ها</td>
                            <td><strong><?= number_format($metrics['performance']['total_requests']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>درخواست‌های موفق</td>
                            <td><strong class="text-success"><?= number_format($metrics['performance']['successful_requests']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>درخواست‌های ناموفق</td>
                            <td><strong class="text-danger"><?= number_format($metrics['performance']['failed_requests']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>Uptime</td>
                            <td><strong class="text-success"><?= number_format($metrics['performance']['uptime'], 1) ?>%</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">آمار خطاها</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کل خطاها</td>
                            <td><strong class="text-danger"><?= number_format($metrics['errors']['total']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>خطاهای ۵۰۰</td>
                            <td><strong class="text-danger"><?= number_format($metrics['errors']['500_errors']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>خطاهای ۴۰۴</td>
                            <td><strong class="text-warning"><?= number_format($metrics['errors']['404_errors']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>خطاهای ۴۰۳</td>
                            <td><strong class="text-warning"><?= number_format($metrics['errors']['403_errors']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>نرخ خطا</td>
                            <td><strong class="text-danger">
                                <?php
                                $errorRate = $metrics['performance']['total_requests'] > 0
                                    ? ($metrics['errors']['total'] / $metrics['performance']['total_requests']) * 100
                                    : 0;
                                echo number_format($errorRate, 2) . '%';
                                ?>
                            </strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Resource Usage -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">استفاده از منابع</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>CPU Usage</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                        <div class="progress-bar bg-info" role="progressbar" style="width: <?= $metrics['resources']['cpu_usage'] ?>%">
                                            <?= $metrics['resources']['cpu_usage'] ?>%
                                        </div>
                                    </div>
                                    <strong class="text-info"><?= $metrics['resources']['cpu_usage'] ?>%</strong>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>Memory Usage</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $metrics['resources']['memory_usage'] ?>%">
                                            <?= $metrics['resources']['memory_usage'] ?>%
                                        </div>
                                    </div>
                                    <strong class="text-warning"><?= $metrics['resources']['memory_usage'] ?>%</strong>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>Disk Usage</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                        <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $metrics['resources']['disk_usage'] ?>%">
                                            <?= $metrics['resources']['disk_usage'] ?>%
                                        </div>
                                    </div>
                                    <strong class="text-danger"><?= $metrics['resources']['disk_usage'] ?>%</strong>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">آمار کاربران</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کاربران آنلاین</td>
                            <td><strong class="text-success"><?= number_format($metrics['users']['online']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>کاربران فعال امروز</td>
                            <td><strong class="text-primary"><?= number_format($metrics['users']['active_today']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>کل کاربران</td>
                            <td><strong><?= number_format($metrics['users']['total']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>کاربران جدید امروز</td>
                            <td><strong class="text-info"><?= number_format($metrics['users']['new_today']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>کاربران غیرفعال</td>
                            <td><strong class="text-muted"><?= number_format($metrics['users']['inactive']) ?></strong></td>
                        </tr>
                    </table>
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
