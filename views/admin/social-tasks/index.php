<?php
$layout     = 'admin';
$ads        = $ads        ?? [];
$stats      = $stats      ?? (object)[];
$filters    = $filters    ?? [];
$page       = $page       ?? 1;
$total      = $total      ?? 0;
$totalPages = $totalPages ?? 1;
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">campaign</i> آگهی‌های اجتماعی</h4>
    <div class="d-flex gap-2">
        <a href="<?= url('/admin/social-tasks/stats') ?>" class="btn btn-outline-secondary btn-sm">آمار کلی</a>
        <a href="<?= url('/admin/social-executions') ?>" class="btn btn-outline-info btn-sm">اجراها</a>
        <a href="<?= url('/admin/social-trust') ?>" class="btn btn-outline-warning btn-sm">Trust Dashboard</a>
    </div>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['label'=>'کل آگهی‌ها',    'val'=>$stats->total ?? 0,        'color'=>'primary'],
        ['label'=>'فعال',           'val'=>$stats->active ?? 0,       'color'=>'success'],
        ['label'=>'در انتظار تأیید','val'=>$stats->pending ?? 0,      'color'=>'warning'],
        ['label'=>'رد شده',         'val'=>$stats->rejected ?? 0,     'color'=>'danger'],
        ['label'=>'بودجه کل (ت)',   'val'=>number_format($stats->total_budget ?? 0), 'color'=>'info'],
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
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach (['pending_review'=>'در انتظار','active'=>'فعال','paused'=>'متوقف','cancelled'=>'لغو','rejected'=>'رد'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($filters['status']??'')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="platform" class="form-select form-select-sm">
                <option value="">همه پلتفرم‌ها</option>
                <?php foreach (['instagram'=>'اینستاگرام','telegram'=>'تلگرام','twitter'=>'توییتر','tiktok'=>'تیک‌تاک'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($filters['platform']??'')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="جستجو نام/ایمیل/عنوان..." value="<?= e($filters['search']??'') ?>">
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
                <tr>
                    <th>#</th><th>عنوان</th><th>تبلیغ‌دهنده</th>
                    <th>پلتفرم</th><th>نوع</th><th>پاداش</th>
                    <th>اجرا/ظرفیت</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($ads)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">موردی یافت نشد</td></tr>
            <?php else: ?>
                <?php foreach ($ads as $ad): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$ad->id ?></td>
                        <td>
                            <a href="<?= url('/admin/social-tasks/'.(int)$ad->id) ?>" class="text-decoration-none fw-bold">
                                <?= e(mb_substr($ad->title ?? '', 0, 35)) ?>
                            </a>
                        </td>
                        <td class="small"><?= e($ad->advertiser_name ?? '') ?><br>
                            <span class="text-muted"><?= e($ad->advertiser_email ?? '') ?></span>
                        </td>
                        <td><span class="badge bg-info text-dark"><?= e($ad->platform ?? '') ?></span></td>
                        <td class="small"><?= e($ad->task_type ?? '') ?></td>
                        <td class="small"><?= number_format($ad->reward ?? 0) ?></td>
                        <td class="small">
                            <?= (int)($ad->total_execs ?? 0) ?> /
                            <?= (int)($ad->max_slots ?? 0) ?>
                        </td>
                        <td>
                            <?php $s = $ad->status ?? ''; ?>
                            <span class="badge bg-<?= $s==='active'?'success':($s==='pending_review'?'warning':($s==='rejected'?'danger':'secondary')) ?>">
                                <?= $s==='active'?'فعال':($s==='pending_review'?'در انتظار':($s==='rejected'?'رد':$s)) ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= e(substr($ad->created_at??'',0,10)) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= url('/admin/social-tasks/'.(int)$ad->id) ?>"
                                   class="btn btn-outline-secondary btn-sm" title="جزئیات">
                                    <i class="material-icons" style="font-size:14px;">visibility</i>
                                </a>
                                <?php if ($s === 'pending_review'): ?>
                                    <button class="btn btn-success btn-sm btn-approve" data-id="<?= (int)$ad->id ?>" title="تأیید">
                                        <i class="material-icons" style="font-size:14px;">check</i>
                                    </button>
                                    <button class="btn btn-danger btn-sm btn-reject" data-id="<?= (int)$ad->id ?>" title="رد">
                                        <i class="material-icons" style="font-size:14px;">close</i>
                                    </button>
                                <?php elseif ($s === 'active'): ?>
                                    <button class="btn btn-warning btn-sm btn-pause" data-id="<?= (int)$ad->id ?>" title="توقف">
                                        <i class="material-icons" style="font-size:14px;">pause</i>
                                    </button>
                                <?php elseif ($s === 'paused'): ?>
                                    <button class="btn btn-success btn-sm btn-resume" data-id="<?= (int)$ad->id ?>" title="ادامه">
                                        <i class="material-icons" style="font-size:14px;">play_arrow</i>
                                    </button>
                                <?php endif; ?>
                            </div>
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
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?>&<?= http_build_query(array_filter($filters)) ?>"
               class="btn btn-sm <?= $i===$page?'btn-primary':'btn-outline-secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<script>
const csrf = '<?= csrf_token() ?>';

function adminPost(url, body = {}) {
    return fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify(body)
    }).then(r => r.json());
}

document.querySelectorAll('.btn-approve').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('تأیید و فعال‌سازی آگهی؟')) return;
        const d = await adminPost(`<?= url('/admin/social-tasks') ?>/${this.dataset.id}/approve`);
        d.success ? location.reload() : alert(d.message);
    });
});

document.querySelectorAll('.btn-reject').forEach(btn => {
    btn.addEventListener('click', async function() {
        const reason = prompt('دلیل رد:');
        if (!reason) return;
        const d = await adminPost(`<?= url('/admin/social-tasks') ?>/${this.dataset.id}/reject`, {reason});
        d.success ? location.reload() : alert(d.message);
    });
});

document.querySelectorAll('.btn-pause').forEach(btn => {
    btn.addEventListener('click', async function() {
        const d = await adminPost(`<?= url('/admin/social-tasks') ?>/${this.dataset.id}/pause`);
        d.success ? location.reload() : alert(d.message);
    });
});

document.querySelectorAll('.btn-resume').forEach(btn => {
    btn.addEventListener('click', async function() {
        const d = await adminPost(`<?= url('/admin/social-tasks') ?>/${this.dataset.id}/resume`);
        d.success ? location.reload() : alert(d.message);
    });
});
</script>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
