<?php
$layout     = 'admin';
$executions = $executions ?? [];
$execStats  = $execStats  ?? (object)[];
$filters    = $filters    ?? [];
$page       = $page       ?? 1;
$total      = $total      ?? 0;
$totalPages = $totalPages ?? 1;
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">task_alt</i> اجراهای تسک اجتماعی</h4>
    <a href="<?= url('/admin/social-tasks') ?>" class="btn btn-outline-secondary btn-sm">آگهی‌ها</a>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['label'=>'کل',         'val'=>$execStats->total??0,          'color'=>'primary'],
        ['label'=>'تأیید',      'val'=>$execStats->approved??0,       'color'=>'success'],
        ['label'=>'در انتظار',  'val'=>$execStats->soft_approved??0,  'color'=>'warning'],
        ['label'=>'رد',         'val'=>$execStats->rejected??0,       'color'=>'danger'],
        ['label'=>'فلگ‌شده',    'val'=>$execStats->flagged??0,        'color'=>'dark'],
        ['label'=>'میانگین امتیاز','val'=>number_format($execStats->avg_score??0,1), 'color'=>'info'],
    ] as $k): ?>
        <div class="col-6 col-md-2">
            <div class="card text-center p-3">
                <div class="text-muted small"><?= $k['label'] ?></div>
                <div class="fw-bold text-<?= $k['color'] ?>"><?= $k['val'] ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- فیلتر -->
<form method="GET" class="card card-body mb-3 p-2">
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <select name="decision" class="form-select form-select-sm">
                <option value="">همه تصمیم‌ها</option>
                <?php foreach (['approved'=>'تأیید','soft_approved'=>'در انتظار','rejected'=>'رد'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($filters['decision']??'')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="platform" class="form-select form-select-sm">
                <option value="">همه پلتفرم‌ها</option>
                <?php foreach (['instagram','telegram','twitter','tiktok'] as $p): ?>
                    <option value="<?= $p ?>" <?= ($filters['platform']??'')===$p?'selected':'' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="flag" class="form-select form-select-sm">
                <option value="">همه</option>
                <option value="1" <?= ($filters['flag']??'')==='1'?'selected':'' ?>>فقط فلگ‌شده</option>
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="نام/ایمیل اجراکننده..." value="<?= e($filters['search']??'') ?>">
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary btn-sm w-100">اعمال</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>اجراکننده</th><th>آگهی</th><th>پلتفرم</th>
                    <th>Trust</th><th>امتیاز</th><th>Time</th><th>Behavior</th>
                    <th>تصمیم</th><th>فلگ</th><th>تاریخ</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($executions)): ?>
                <tr><td colspan="12" class="text-center py-4 text-muted">موردی یافت نشد</td></tr>
            <?php else: ?>
                <?php foreach ($executions as $ex): ?>
                    <?php $d = $ex->decision ?? ''; ?>
                    <tr class="<?= $ex->flag_review ? 'table-warning' : '' ?>">
                        <td class="small text-muted"><?= (int)$ex->id ?></td>
                        <td class="small"><?= e($ex->executor_name??'') ?></td>
                        <td class="small"><?= e(mb_substr($ex->ad_title??'',0,25)) ?></td>
                        <td><span class="badge bg-info text-dark"><?= e($ex->platform??'') ?></span></td>
                        <td><span class="badge bg-<?= ($ex->trust_score??50)>=60?'success':(($ex->trust_score??50)>=40?'warning':'danger') ?>"><?= (int)($ex->trust_score??50) ?></span></td>
                        <td class="fw-bold text-<?= ($ex->task_score??0)>=70?'success':(($ex->task_score??0)>=40?'warning':'danger') ?>">
                            <?= number_format($ex->task_score??0,1) ?>
                        </td>
                        <td class="small"><?= (int)($ex->time_score??0) ?></td>
                        <td class="small"><?= (int)($ex->behavior_score??0) ?></td>
                        <td><span class="badge bg-<?= $d==='approved'?'success':($d==='rejected'?'danger':'warning') ?>">
                            <?= $d==='approved'?'تأیید':($d==='rejected'?'رد':'انتظار') ?>
                        </span></td>
                        <td><?= $ex->flag_review ? '<span class="badge bg-danger">⚑</span>' : '' ?></td>
                        <td class="small text-muted"><?= e(substr($ex->created_at??'',0,10)) ?></td>
                        <td><a href="<?= url('/admin/social-executions/'.(int)$ex->id) ?>"
                               class="btn btn-outline-secondary btn-sm">
                            <i class="material-icons" style="font-size:14px;">open_in_new</i>
                        </a></td>
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
            <a href="?page=<?= $i ?>&<?= http_build_query(array_filter($filters)) ?>"
               class="btn btn-sm <?= $i===$page?'btn-primary':'btn-outline-secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
