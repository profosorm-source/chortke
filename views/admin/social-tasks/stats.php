<?php
$layout = 'admin';
$stats  = $stats ?? (object)[];
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">bar_chart</i> آمار کلی SocialTask</h4>
    <a href="<?= url('/admin/social-tasks') ?>" class="btn btn-outline-secondary btn-sm">برگشت</a>
</div>

<div class="row g-3">
    <?php $cards = [
        ['عنوان'=>'آگهی‌های کل',        'val'=>$stats->total_ads??0,         'icon'=>'campaign',      'color'=>'primary'],
        ['عنوان'=>'آگهی‌های فعال',       'val'=>$stats->active_ads??0,        'icon'=>'check_circle',  'color'=>'success'],
        ['عنوان'=>'در انتظار تأیید',     'val'=>$stats->pending_ads??0,       'icon'=>'hourglass_empty','color'=>'warning'],
        ['عنوان'=>'کل اجراها',           'val'=>$stats->total_execs??0,       'icon'=>'task_alt',      'color'=>'primary'],
        ['عنوان'=>'اجراهای تأیید شده',   'val'=>$stats->approved_execs??0,    'icon'=>'done_all',      'color'=>'success'],
        ['عنوان'=>'اجراهای رد شده',      'val'=>$stats->rejected_execs??0,    'icon'=>'cancel',        'color'=>'danger'],
        ['عنوان'=>'فلگ‌شده',             'val'=>$stats->flagged_execs??0,     'icon'=>'flag',          'color'=>'dark'],
        ['عنوان'=>'میانگین امتیاز تسک',  'val'=>number_format($stats->avg_score??0,1),'icon'=>'star', 'color'=>'info'],
        ['عنوان'=>'کاربران Trust پایین', 'val'=>$stats->low_trust_users??0,   'icon'=>'person_off',    'color'=>'danger'],
        ['عنوان'=>'میانگین Trust',       'val'=>number_format($stats->avg_trust??0,1),'icon'=>'verified_user','color'=>'success'],
    ]; ?>
    <?php foreach ($cards as $c): ?>
        <div class="col-6 col-md-3">
            <div class="card p-3">
                <div class="d-flex align-items-center gap-3">
                    <i class="material-icons text-<?= $c['color'] ?>" style="font-size:32px;"><?= $c['icon'] ?></i>
                    <div>
                        <div class="text-muted small"><?= $c['عنوان'] ?></div>
                        <div class="fw-bold fs-5 text-<?= $c['color'] ?>"><?= $c['val'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mt-2">
    <div class="col-md-4">
        <div class="card p-3 text-center">
            <?php $total = max(1, (int)($stats->total_execs??0)); $app = (int)($stats->approved_execs??0); ?>
            <div class="text-muted small">نرخ تأیید کلی</div>
            <div class="display-5 fw-bold text-success"><?= round($app*100/$total,1) ?>%</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3 text-center">
            <?php $rej = (int)($stats->rejected_execs??0); ?>
            <div class="text-muted small">نرخ رد کلی</div>
            <div class="display-5 fw-bold text-danger"><?= round($rej*100/$total,1) ?>%</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3 text-center">
            <?php $flag = (int)($stats->flagged_execs??0); ?>
            <div class="text-muted small">نرخ فلگ</div>
            <div class="display-5 fw-bold text-warning"><?= round($flag*100/$total,1) ?>%</div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
