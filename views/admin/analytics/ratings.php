<?php view('layouts.header', ['title' => $title]) ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= h($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics/ratings?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics/ratings?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">هفته</a>
                <a href="<?= url('/admin/analytics/ratings?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">ماه</a>
                <a href="<?= url('/admin/analytics/ratings?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">سال</a>
            </div>
        </div>
    </div>

    <!-- Rating Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">کل امتیازات</h6>
                    <h2 class="text-primary"><?= number_format($metrics['ratings']['total']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">میانگین امتیاز</h6>
                    <h2 class="text-success"><?= number_format($metrics['ratings']['average'], 1) ?>/۵</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">امتیازات ۵ ستاره</h6>
                    <h2 class="text-warning"><?= number_format($metrics['ratings']['five_star']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">امتیازات ۱ ستاره</h6>
                    <h2 class="text-danger"><?= number_format($metrics['ratings']['one_star']) ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Rating Distribution -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">توزیع امتیازات</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($metrics['distribution'])): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>ستاره</th>
                                        <th>تعداد</th>
                                        <th>درصد</th>
                                        <th>نمودار</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total = array_sum(array_map(function($item) { return $item->count; }, $metrics['distribution']));
                                    foreach ($metrics['distribution'] as $rating):
                                        $percentage = $total > 0 ? ($rating->count / $total) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="fas fa-star <?= $i <= $rating->stars ? 'text-warning' : 'text-muted' ?>"></i>
                                                        <?php endfor; ?>
                                                    </span>
                                                    <strong><?= $rating->stars ?> ستاره</strong>
                                                </div>
                                            </td>
                                            <td><?= number_format($rating->count) ?></td>
                                            <td><?= round($percentage, 1) ?>%</td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $percentage ?>%">
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

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">آمار کلی امتیازات</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کل امتیازات</td>
                            <td><strong><?= number_format($metrics['ratings']['total']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>میانگین امتیاز</td>
                            <td><strong class="text-success"><?= number_format($metrics['ratings']['average'], 1) ?>/۵</strong></td>
                        </tr>
                        <tr>
                            <td>امتیازات ۵ ستاره</td>
                            <td><strong class="text-warning"><?= number_format($metrics['ratings']['five_star']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>امتیازات ۱ ستاره</td>
                            <td><strong class="text-danger"><?= number_format($metrics['ratings']['one_star']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>امتیازات با کامنت</td>
                            <td><strong class="text-info"><?= number_format($metrics['ratings']['with_comments']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>امتیازات بدون کامنت</td>
                            <td><strong class="text-muted"><?= number_format($metrics['ratings']['without_comments']) ?></strong></td>
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
