<?php
$layout      = 'user';
$trust_score = $trust_score  ?? 50;
$stats       = $stats        ?? (object)['total'=>0,'approved'=>0,'soft_approved'=>0,'rejected'=>0,'avg_score'=>0,'success_rate'=>0];
$recent      = $recent       ?? [];
$weekly_stats= $weekly_stats ?? ['total'=>0,'good_tasks'=>0,'rejected'=>0,'soft_approved'=>0,'avg_score'=>0];
ob_start();
?>
<div class="page-header d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">dashboard</i> داشبورد تسک‌ها</h4>
    <a href="<?= url('/social-tasks') ?>" class="btn btn-primary btn-sm">مشاهده تسک‌ها</a>
</div>

<!-- KPI cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">Trust Score</div>
            <div class="fw-bold fs-4 text-primary"><?= number_format($trust_score, 0) ?></div>
            <div class="progress mt-1" style="height:4px;">
                <div class="progress-bar bg-primary" style="width:<?= min(100,$trust_score) ?>%"></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">نرخ موفقیت</div>
            <div class="fw-bold fs-4 text-success"><?= number_format($stats->success_rate ?? 0, 1) ?>%</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">کل تأیید شده</div>
            <div class="fw-bold fs-4 text-success"><?= number_format($stats->approved ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">میانگین امتیاز</div>
            <div class="fw-bold fs-4"><?= number_format($stats->avg_score ?? 0, 1) ?></div>
        </div>
    </div>
</div>

<!-- آمار هفته -->
<div class="card mb-4">
    <div class="card-header fw-bold">آمار ۷ روز اخیر</div>
    <div class="card-body">
        <div class="row text-center g-3">
            <div class="col-4">
                <div class="text-muted small">تسک‌های خوب (≥70)</div>
                <div class="fw-bold text-success fs-5"><?= $weekly_stats['good_tasks'] ?></div>
            </div>
            <div class="col-4">
                <div class="text-muted small">رد شده</div>
                <div class="fw-bold text-danger fs-5"><?= $weekly_stats['rejected'] ?></div>
            </div>
            <div class="col-4">
                <div class="text-muted small">میانگین امتیاز</div>
                <div class="fw-bold fs-5"><?= number_format($weekly_stats['avg_score'] ?? 0, 1) ?></div>
            </div>
        </div>
        <?php if ($weekly_stats['rejected'] === 0 && $weekly_stats['good_tasks'] >= 5): ?>
            <div class="alert alert-success mt-3 mb-0 small">
                <i class="material-icons align-middle" style="font-size:16px;">trending_up</i>
                شرایط بهبود Trust Score هفتگی را دارید!
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- تاریخچه اخیر -->
<?php if (!empty($recent)): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold">آخرین تسک‌ها</span>
        <a href="<?= url('/social-tasks/history') ?>" class="btn btn-outline-secondary btn-sm">همه</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>پلتفرم</th><th>نوع</th><th>امتیاز</th><th>نتیجه</th><th>تاریخ</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td><?= e($row->platform ?? '') ?></td>
                    <td><?= e($row->task_type ?? '') ?></td>
                    <td><?= number_format($row->task_score ?? 0, 1) ?></td>
                    <td>
                        <?php $d = $row->decision ?? ''; ?>
                        <span class="badge bg-<?= $d==='approved'?'success':($d==='rejected'?'danger':'warning') ?>">
                            <?= $d==='approved'?'تأیید':($d==='rejected'?'رد':'در انتظار') ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= e(substr($row->created_at ?? '', 0, 10)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
