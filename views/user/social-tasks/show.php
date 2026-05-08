<?php
$layout     = 'user';
$ad         = $ad         ?? null;
$executions = $executions ?? [];
ob_start();
if (!$ad) { redirect(url('/social-ads')); exit; }
$totalExec   = (int)($ad->total_executions ?? 0);
$approved    = (int)($ad->approved ?? 0);
$softApproved= (int)($ad->soft_approved ?? 0);
$rejected    = (int)($ad->rejected ?? 0);
$pending     = $totalExec - $approved - $softApproved - $rejected;
$successRate = $totalExec > 0 ? round(($approved + $softApproved) * 100 / $totalExec, 1) : 0;
$budgetUsed  = ($approved + $softApproved) * ($ad->reward ?? 0);
$budgetTotal = ($ad->max_slots ?? 0) * ($ad->reward ?? 0);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><?= e($ad->title ?? '') ?></h4>
        <span class="badge bg-info text-dark"><?= e($ad->platform ?? '') ?></span>
        <span class="badge bg-secondary"><?= e($ad->task_type ?? '') ?></span>
    </div>
    <a href="<?= url('/social-ads') ?>" class="btn btn-outline-secondary btn-sm">برگشت</a>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['label'=>'کل اجراها',    'value'=>$totalExec,        'color'=>'primary'],
        ['label'=>'تأیید شده',   'value'=>$approved,          'color'=>'success'],
        ['label'=>'رد شده',      'value'=>$rejected,          'color'=>'danger'],
        ['label'=>'در انتظار',   'value'=>$pending,           'color'=>'warning'],
        ['label'=>'نرخ موفقیت',  'value'=>$successRate.'%',   'color'=>'info'],
        ['label'=>'میانگین امتیاز','value'=>number_format($ad->avg_score??0,1), 'color'=>'secondary'],
    ] as $kpi): ?>
        <div class="col-6 col-md-2">
            <div class="card text-center p-2">
                <div class="text-muted" style="font-size:11px;"><?= $kpi['label'] ?></div>
                <div class="fw-bold text-<?= $kpi['color'] ?>"><?= $kpi['value'] ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- بودجه -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
            <span class="small text-muted">بودجه مصرف‌شده</span>
            <span class="small fw-bold"><?= number_format($budgetUsed) ?> / <?= number_format($budgetTotal) ?> تومان</span>
        </div>
        <div class="progress" style="height:8px;">
            <div class="progress-bar bg-success" style="width:<?= $budgetTotal > 0 ? min(100, round($budgetUsed*100/$budgetTotal)) : 0 ?>%"></div>
        </div>
    </div>
</div>

<!-- لیست اجراها -->
<div class="card">
    <div class="card-header fw-bold">لیست اجراها</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>اجراکننده</th><th>Trust</th><th>امتیاز تسک</th><th>وضعیت</th><th>زمان</th><th>عملیات</th>
            </tr></thead>
            <tbody>
            <?php if (empty($executions)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">هنوز اجرایی ثبت نشده</td></tr>
            <?php else: ?>
                <?php foreach ($executions as $exec): ?>
                    <tr>
                        <td><?= e($exec->executor_name ?? '') ?></td>
                        <td><?= number_format($exec->executor_trust ?? 50, 0) ?></td>
                        <td>
                            <?php $sc = (float)($exec->task_score ?? 0); ?>
                            <span class="fw-bold text-<?= $sc>=70?'success':($sc>=40?'warning':'danger') ?>">
                                <?= number_format($sc, 1) ?>
                            </span>
                        </td>
                        <td>
                            <?php $d = $exec->decision ?? $exec->status ?? ''; ?>
                            <span class="badge bg-<?= $d==='approved'?'success':($d==='rejected'?'danger':'warning') ?>">
                                <?= $d==='approved'?'تأیید':($d==='rejected'?'رد':'در انتظار') ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= e(substr($exec->created_at ?? '', 0, 10)) ?></td>
                        <td>
                            <a href="<?= url('/social-ads/execution/'.($exec->id??'')) ?>"
                               class="btn btn-outline-secondary btn-sm">جزئیات</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
