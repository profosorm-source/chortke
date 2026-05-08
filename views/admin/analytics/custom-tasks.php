<?php view('layouts.header', ['title' => $title]) ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= h($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics/custom-tasks?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics/custom-tasks?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">هفته</a>
                <a href="<?= url('/admin/analytics/custom-tasks?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">ماه</a>
                <a href="<?= url('/admin/analytics/custom-tasks?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">سال</a>
            </div>
        </div>
    </div>

    <!-- Custom Task Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">کل وظایف</h6>
                    <h2 class="text-primary"><?= number_format($metrics['tasks']['total']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">وظایف فعال</h6>
                    <h2 class="text-success"><?= number_format($metrics['tasks']['active']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">کل ارسال‌ها</h6>
                    <h2 class="text-info"><?= number_format($metrics['submissions']['total']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">بودجه کل</h6>
                    <h2 class="text-warning"><?= number_format($metrics['tasks']['total_budget']) ?> تومان</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Tasks Overview -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">آمار وظایف</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کل وظایف</td>
                            <td><strong><?= number_format($metrics['tasks']['total']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>وظایف فعال</td>
                            <td><strong class="text-success"><?= number_format($metrics['tasks']['active']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>کل ارسال‌ها</td>
                            <td><strong><?= number_format($metrics['tasks']['total_submissions']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>میانگین پاداش</td>
                            <td><strong class="text-info"><?= number_format($metrics['tasks']['avg_reward']) ?> تومان</strong></td>
                        </tr>
                        <tr>
                            <td>بودجه کل</td>
                            <td><strong class="text-warning"><?= number_format($metrics['tasks']['total_budget']) ?> تومان</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">آمار ارسال‌ها</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کل ارسال‌ها</td>
                            <td><strong><?= number_format($metrics['submissions']['total']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>تایید‌شده</td>
                            <td><strong class="text-success"><?= number_format($metrics['submissions']['approved']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>رد‌شده</td>
                            <td><strong class="text-danger"><?= number_format($metrics['submissions']['rejected']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>منتظر بررسی</td>
                            <td><strong class="text-warning"><?= number_format($metrics['submissions']['pending']) ?></strong></td>
                        </tr>
                    </table>

                    <?php
                    $total = $metrics['submissions']['total'];
                    $approved = $metrics['submissions']['approved'];
                    $approvalRate = $total > 0 ? ($approved / $total) * 100 : 0;
                    ?>
                    <div class="mt-3">
                        <strong>نرخ تایید:</strong>
                        <div class="progress" style="height: 25px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $approvalRate ?>%">
                                <?= round($approvalRate, 1) ?>%
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
