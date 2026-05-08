<?php $title = 'مدیریت قرعه‌کشی'; $layout = 'admin'; ob_start(); ?>

<div class="content-header">
    <h4><i class="material-icons">casino</i> مدیریت قرعه‌کشی</h4>
    <a href="<?= url('/admin/lottery/create') ?>" class="btn btn-primary btn-sm">
        <i class="material-icons">add</i> دوره جدید
    </a>
</div>

<div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-info">
            <span class="stat-label">کل دوره‌ها</span>
            <span class="stat-value"><?= e($stats->total ?? 0) ?></span>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-info">
            <span class="stat-label">فعال</span>
            <span class="stat-value"><?= e($stats->active_count ?? 0) ?></span>
        </div>
    </div>
    <div class="stat-card stat-orange">
        <div class="stat-info">
            <span class="stat-label">تکمیل شده</span>
            <span class="stat-value"><?= e($stats->completed_count ?? 0) ?></span>
        </div>
    </div>
    <div class="stat-card stat-purple">
        <div class="stat-info">
            <span class="stat-label">کل جوایز</span>
            <span class="stat-value"><?= number_format($stats->total_prizes ?? 0) ?></span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5>دوره‌ها (<?= e($total) ?>)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($rounds)): ?>
            <p class="text-center text-muted">دوره‌ای یافت نشد.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th><th>عنوان</th><th>جایزه</th><th>شرکت‌کننده</th>
                        <th>وضعیت</th><th>برنده</th><th>تاریخ</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rounds as $r): ?>
                    <?php
                    $pCount = $participationCounts[$r->id] ?? 0;
                    $sl = [
                        'active' => ['فعال', 'badge-success'],
                        'voting' => ['رأی‌گیری', 'badge-info'],
                        'calculating' => ['محاسبه', 'badge-warning'],
                        'completed' => ['تکمیل', 'badge-primary'],
                        'cancelled' => ['لغو', 'badge-danger'],
                    ][$r->status] ?? ['؟', 'badge-secondary'];
                    ?>
                    <tr>
                        <td><?= e($r->id) ?></td>
                        <td><a href="<?= url('/admin/lottery/' . $r->id) ?>"><?= e($r->title) ?></a></td>
                        <td><?= number_format($r->prize_amount) ?></td>
                        <td><?= e($pCount) ?></td>
                        <td><span class="badge <?= e($sl[1]) ?>"><?= e($sl[0]) ?></span></td>
                        <td><?= e($r->winner_name ?? '-') ?></td>
                        <td><?= e(to_jalali($r->start_date ?? '')) ?></td>
                        <td>
                            <a href="<?= url('/admin/lottery/' . $r->id) ?>" class="btn btn-xs btn-outline-primary">
                                <i class="material-icons">visibility</i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>