<?php
$layout  = 'user';
$summary = $summary ?? [];
ob_start();
$summary = (object)array_merge(['total_ads'=>0,'total_budget'=>0,'spent'=>0,'total_executions'=>0,'avg_score'=>0], $summary);
?>
<div class="page-header d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">bar_chart</i> داشبورد تبلیغ‌دهنده</h4>
    <div class="d-flex gap-2">
        <a href="<?= url('/social-ads') ?>" class="btn btn-outline-secondary btn-sm">آگهی‌های من</a>
        <a href="<?= url('/social-ads/create') ?>" class="btn btn-primary btn-sm">+ ثبت آگهی</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">کل آگهی‌ها</div>
            <div class="fw-bold fs-4"><?= number_format($summary->total_ads ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">کل اجراها</div>
            <div class="fw-bold fs-4 text-primary"><?= number_format($summary->total_executions ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">هزینه مصرف‌شده</div>
            <div class="fw-bold fs-4 text-warning"><?= number_format($summary->spent ?? 0) ?> <small>تومان</small></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">میانگین امتیاز</div>
            <div class="fw-bold fs-4"><?= number_format($summary->avg_score ?? 0, 1) ?></div>
        </div>
    </div>
</div>

<?php if ($summary->total_budget > 0): ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
            <span class="small text-muted">مصرف کل بودجه</span>
            <span class="small"><?= number_format($summary->spent??0) ?> / <?= number_format($summary->total_budget??0) ?> تومان</span>
        </div>
        <div class="progress" style="height:10px;">
            <div class="progress-bar bg-warning"
                 style="width:<?= $summary->total_budget > 0 ? min(100,round(($summary->spent??0)*100/($summary->total_budget??1))) : 0 ?>%"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="text-center">
    <a href="<?= url('/social-ads') ?>" class="btn btn-primary">مشاهده همه آگهی‌ها</a>
</div>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
