<?php
$layout      = 'user';
$history     = $history     ?? [];
$stats       = $stats       ?? (object)['total'=>0,'approved'=>0,'rejected'=>0,'success_rate'=>0];
$trust_score = $trust_score ?? 50;
$page        = $page        ?? 1;
ob_start();
?>
<div class="page-header d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">history</i> تاریخچه تسک‌ها</h4>
    <span class="badge bg-primary">Trust: <?= number_format($trust_score, 0) ?></span>
</div>

<!-- آمار کلی -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">کل</div>
            <div class="fw-bold fs-5"><?= number_format($stats->total ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">تأیید شده</div>
            <div class="fw-bold fs-5 text-success"><?= number_format($stats->approved ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">رد شده</div>
            <div class="fw-bold fs-5 text-danger"><?= number_format($stats->rejected ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted small">نرخ موفقیت</div>
            <div class="fw-bold fs-5"><?= number_format($stats->success_rate ?? 0, 1) ?>%</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr>
                <th>عنوان</th><th>پلتفرم</th><th>نوع</th>
                <th>امتیاز</th><th>نتیجه</th><th>تاریخ</th>
            </tr></thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">هیچ تاریخچه‌ای وجود ندارد</td></tr>
            <?php else: ?>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= e(mb_substr($row->title ?? '', 0, 40)) ?></td>
                        <td><?= e($row->platform ?? '') ?></td>
                        <td><?= e($row->task_type ?? '') ?></td>
                        <td>
                            <?php $score = (float)($row->task_score ?? 0); ?>
                            <span class="fw-bold text-<?= $score>=70?'success':($score>=40?'warning':'danger') ?>">
                                <?= number_format($score, 1) ?>
                            </span>
                        </td>
                        <td>
                            <?php $d = $row->decision ?? ''; ?>
                            <span class="badge bg-<?= $d==='approved'?'success':($d==='rejected'?'danger':'warning') ?>">
                                <?= $d==='approved'?'تأیید':($d==='rejected'?'رد':'در انتظار') ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= e(substr($row->created_at ?? '', 0, 10)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- pagination ساده -->
<div class="d-flex justify-content-between mt-3">
    <?php if ($page > 1): ?>
        <a href="?page=<?= $page-1 ?>" class="btn btn-outline-secondary btn-sm">قبلی</a>
    <?php else: ?><span></span><?php endif; ?>
    <?php if (count($history) === 20): ?>
        <a href="?page=<?= $page+1 ?>" class="btn btn-outline-secondary btn-sm">بعدی</a>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
