<?php view('layouts.header', ['title' => $title]) ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= h($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics/social-tasks?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics/social-tasks?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">هفته</a>
                <a href="<?= url('/admin/analytics/social-tasks?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">ماه</a>
                <a href="<?= url('/admin/analytics/social-tasks?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">سال</a>
            </div>
        </div>
    </div>

    <!-- Social Task Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">آگهی‌های فعال</h6>
                    <h2 class="text-success"><?= number_format($metrics['ads']['active']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">کل اجراها</h6>
                    <h2 class="text-primary"><?= number_format($metrics['executions']['total']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">نرخ تایید</h6>
                    <h2 class="text-info"><?= $metrics['executions']['approval_rate'] ?>%</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">میانگین امتیاز</h6>
                    <h2 class="text-warning"><?= number_format($metrics['executions']['avg_score'], 1) ?>/۱۰</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Ads Overview -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">آمار آگهی‌ها</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کل آگهی‌ها</td>
                            <td><strong><?= number_format($metrics['ads']['total']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>آگهی‌های فعال</td>
                            <td><strong class="text-success"><?= number_format($metrics['ads']['active']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>کل اسلات‌ها</td>
                            <td><strong><?= number_format($metrics['ads']['total_slots']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>بودجه کل</td>
                            <td><strong class="text-info"><?= number_format($metrics['ads']['total_budget']) ?> تومان</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">آمار اجراها</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کل اجراها</td>
                            <td><strong><?= number_format($metrics['executions']['total']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>تایید‌شده</td>
                            <td><strong class="text-success"><?= number_format($metrics['executions']['approved']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>رد‌شده</td>
                            <td><strong class="text-danger"><?= number_format($metrics['executions']['rejected']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>منتظر بررسی</td>
                            <td><strong class="text-warning"><?= number_format($metrics['executions']['pending']) ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Platform Distribution -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">توزیع بر اساس پلتفرم</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($metrics['platforms'])): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>پلتفرم</th>
                                        <th>تعداد</th>
                                        <th>درصد</th>
                                        <th>میانگین پاداش</th>
                                        <th>نمودار</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total = array_sum(array_map(function($item) { return $item->count; }, $metrics['platforms']));
                                    foreach ($metrics['platforms'] as $platform):
                                        $percentage = $total > 0 ? ($platform->count / $total) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td><strong><?= h($platform->platform) ?></strong></td>
                                            <td><?= number_format($platform->count) ?></td>
                                            <td><?= round($percentage, 1) ?>%</td>
                                            <td><?= number_format($platform->avg_reward) ?> تومان</td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar" role="progressbar" style="width: <?= $percentage ?>%">
                                                        <?= round($percentage, 0) ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">داده‌ای برای نمایش وجود ندارد</div>
                    <?php endif; ?>
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
