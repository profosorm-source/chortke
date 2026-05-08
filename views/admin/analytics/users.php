<?php view('layouts.header', ['title' => $title]) ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= h($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics/users?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics/users?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">هفته</a>
                <a href="<?= url('/admin/analytics/users?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">ماه</a>
                <a href="<?= url('/admin/analytics/users?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">سال</a>
            </div>
        </div>
    </div>

    <!-- User Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">کل کاربران</h6>
                    <h2 class="text-primary"><?= number_format($metrics['total_users']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">کاربران فعال</h6>
                    <h2 class="text-success"><?= number_format($metrics['active_users']) ?></h2>
                    <small class="text-muted">7 روز گذشته</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">کاربران جدید</h6>
                    <h2 class="text-info"><?= number_format($metrics['new_users']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">نرخ فعالیت</h6>
                    <h2 class="text-warning">
                        <?= round(($metrics['total_users'] > 0 ? ($metrics['active_users'] / $metrics['total_users']) * 100 : 0), 1) ?>%
                    </h2>
                </div>
            </div>
        </div>
    </div>

    <!-- KYC Status -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">وضعیت KYC</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h6 class="text-muted">تأیید‌شده</h6>
                            <h3 class="text-success"><?= number_format($metrics['kyc_verified']) ?></h3>
                            <small class="text-muted">
                                (<?= round(($metrics['total_users'] > 0 ? ($metrics['kyc_verified'] / $metrics['total_users']) * 100 : 0), 1) ?>%)
                            </small>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted">منتظر بررسی</h6>
                            <h3 class="text-warning"><?= number_format($metrics['kyc_pending']) ?></h3>
                            <small class="text-muted">
                                (<?= round(($metrics['total_users'] > 0 ? ($metrics['kyc_pending'] / $metrics['total_users']) * 100 : 0), 1) ?>%)
                            </small>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted">رد‌شده</h6>
                            <h3 class="text-danger"><?= number_format($metrics['kyc_rejected']) ?></h3>
                            <small class="text-muted">
                                (<?= round(($metrics['total_users'] > 0 ? ($metrics['kyc_rejected'] / $metrics['total_users']) * 100 : 0), 1) ?>%)
                            </small>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted">ارسال نکرده</h6>
                            <h3 class="text-secondary"><?= number_format($metrics['kyc_not_submitted']) ?></h3>
                            <small class="text-muted">
                                (<?= round(($metrics['total_users'] > 0 ? ($metrics['kyc_not_submitted'] / $metrics['total_users']) * 100 : 0), 1) ?>%)
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Levels -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">توزیع کاربران بر اساس سطح</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($metrics['users_by_level'])): ?>
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>سطح</th>
                                    <th>تعداد کاربران</th>
                                    <th>درصد</th>
                                    <th>نمودار</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total = array_sum(array_map(function($item) { return $item->count; }, $metrics['users_by_level']));
                                foreach ($metrics['users_by_level'] as $level): 
                                    $percentage = $total > 0 ? ($level->count / $total) * 100 : 0;
                                ?>
                                    <tr>
                                        <td><strong><?= h($level->level_name ?? 'بدون سطح') ?></strong></td>
                                        <td><?= number_format($level->count) ?></td>
                                        <td><?= round($percentage, 1) ?>%</td>
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
