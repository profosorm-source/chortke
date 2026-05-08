<?php
$layout        = 'admin';
$lowTrustUsers = $lowTrustUsers ?? [];
$trustStats    = $trustStats    ?? (object)[];
$page          = $page          ?? 1;
$total         = $total         ?? 0;
$totalPages    = $totalPages    ?? 1;
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">verified_user</i> Trust Score Dashboard</h4>
    <a href="<?= url('/admin/social-tasks') ?>" class="btn btn-outline-secondary btn-sm">آگهی‌ها</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['label'=>'کل کاربران',   'val'=>$trustStats->total_users??0,  'color'=>'primary'],
        ['label'=>'Trust بالا (≥60)','val'=>$trustStats->high_trust??0, 'color'=>'success'],
        ['label'=>'Trust متوسط',  'val'=>$trustStats->mid_trust??0,    'color'=>'warning'],
        ['label'=>'Trust پایین (<40)','val'=>$trustStats->low_trust??0,'color'=>'danger'],
        ['label'=>'میانگین Trust', 'val'=>number_format($trustStats->avg_trust??0,1), 'color'=>'info'],
    ] as $k): ?>
        <div class="col-6 col-md-2">
            <div class="card text-center p-3">
                <div class="text-muted small"><?= $k['label'] ?></div>
                <div class="fw-bold fs-5 text-<?= $k['color'] ?>"><?= $k['val'] ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header fw-bold">کاربران با Trust پایین (< 40)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>کاربر</th><th>Trust</th><th>کل اجرا</th><th>رد شده</th><th>تاریخ آخرین تغییر</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($lowTrustUsers)): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">کاربر با Trust پایین وجود ندارد</td></tr>
            <?php else: ?>
                <?php foreach ($lowTrustUsers as $u): ?>
                    <tr>
                        <td class="small"><strong><?= e($u->full_name??'') ?></strong><br>
                            <span class="text-muted"><?= e($u->email??'') ?></span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:6px;min-width:60px;">
                                    <div class="progress-bar bg-danger" style="width:<?= min(100,(int)($u->trust_score??0)) ?>%"></div>
                                </div>
                                <span class="badge bg-danger"><?= (int)($u->trust_score??0) ?></span>
                            </div>
                        </td>
                        <td><?= (int)($u->total_execs??0) ?></td>
                        <td class="text-danger fw-bold"><?= (int)($u->rejected_execs??0) ?></td>
                        <td class="text-muted small"><?= e(substr($u->updated_at??'',0,10)) ?></td>
                        <td>
                            <a href="<?= url('/admin/social-trust/user/'.(int)$u->user_id) ?>"
                               class="btn btn-outline-secondary btn-sm">جزئیات</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <div class="d-flex gap-1 mt-3 flex-wrap">
        <?php for ($i=1; $i<=$totalPages; $i++): ?>
            <a href="?page=<?= $i ?>" class="btn btn-sm <?= $i===$page?'btn-primary':'btn-outline-secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
