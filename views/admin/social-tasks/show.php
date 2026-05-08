<?php
$layout  = 'admin';
$ad      = $ad      ?? null;
$adStats = $adStats ?? null;
$executions = $executions ?? [];
ob_start();
if (!$ad) { redirect(url('/admin/social-tasks')); exit; }
$s = $ad->status ?? '';
$totalEx = (int)($adStats->total_executions ?? 0);
$approved = (int)($adStats->approved ?? 0);
$rejected = (int)($adStats->rejected ?? 0);
$successRate = $totalEx > 0 ? round(($approved / $totalEx) * 100, 1) : 0;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0">آگهی #<?= (int)$ad->id ?> — <?= e($ad->title ?? '') ?></h4>
        <span class="badge bg-info text-dark"><?= e($ad->platform ?? '') ?></span>
        <span class="badge bg-secondary"><?= e($ad->task_type ?? '') ?></span>
        <span class="badge bg-<?= $s==='active'?'success':($s==='pending_review'?'warning':($s==='rejected'?'danger':'secondary')) ?>">
            <?= $s==='active'?'فعال':($s==='pending_review'?'در انتظار':($s==='rejected'?'رد':$s)) ?>
        </span>
    </div>
    <a href="<?= url('/admin/social-tasks') ?>" class="btn btn-outline-secondary btn-sm">برگشت</a>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['label'=>'کل اجراها',    'val'=>$totalEx,                              'color'=>'primary'],
        ['label'=>'تأیید شده',   'val'=>$approved,                             'color'=>'success'],
        ['label'=>'رد شده',      'val'=>$rejected,                             'color'=>'danger'],
        ['label'=>'نرخ موفقیت',  'val'=>$successRate.'%',                      'color'=>'info'],
        ['label'=>'میانگین امتیاز','val'=>number_format($adStats->avg_score??0,1),'color'=>'secondary'],
        ['label'=>'میانگین زمان', 'val'=>(int)($adStats->avg_time??0).'s',     'color'=>'dark'],
    ] as $k): ?>
        <div class="col-6 col-md-2">
            <div class="card text-center p-3">
                <div class="text-muted small"><?= $k['label'] ?></div>
                <div class="fw-bold text-<?= $k['color'] ?>"><?= $k['val'] ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <!-- اطلاعات آگهی -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-bold">اطلاعات آگهی</div>
            <div class="card-body">
                <?php $rows = [
                    'تبلیغ‌دهنده' => ($ad->advertiser_name??'').' ('.(e($ad->advertiser_email??'')).')',
                    'پاداش واحد'  => number_format($ad->reward ?? 0).' تومان',
                    'ظرفیت کل'    => number_format($ad->max_slots ?? 0),
                    'باقیمانده'   => number_format($ad->remaining_slots ?? 0),
                    'لینک هدف'    => '<a href="'.e($ad->target_url??'#').'" target="_blank" rel="noopener">'.e($ad->target_url??'').'</a>',
                    'نام‌کاربری'  => $ad->target_username ? '@'.e($ad->target_username) : '—',
                    'تاریخ ایجاد' => e(substr($ad->created_at??'',0,10)),
                ]; ?>
                <?php foreach ($rows as $label => $val): ?>
                    <div class="d-flex border-bottom py-2">
                        <div class="text-muted small" style="min-width:110px"><?= $label ?></div>
                        <div class="small"><?= $val ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!empty($ad->description)): ?>
                    <div class="mt-2 small text-muted"><?= nl2br(e($ad->description)) ?></div>
                <?php endif; ?>
                <?php if (!empty($ad->reject_reason)): ?>
                    <div class="alert alert-danger mt-2 small mb-0">دلیل رد: <?= e($ad->reject_reason) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- عملیات ادمین -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-bold">عملیات مدیریت</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($s === 'pending_review'): ?>
                        <button class="btn btn-success" id="btn-approve">
                            <i class="material-icons align-middle" style="font-size:16px;">check</i> تأیید و فعال‌سازی
                        </button>
                        <button class="btn btn-danger" id="btn-reject">
                            <i class="material-icons align-middle" style="font-size:16px;">close</i> رد آگهی
                        </button>
                    <?php elseif ($s === 'active'): ?>
                        <button class="btn btn-warning" id="btn-pause">توقف</button>
                        <button class="btn btn-danger" id="btn-cancel">لغو + بازگشت بودجه</button>
                    <?php elseif ($s === 'paused'): ?>
                        <button class="btn btn-success" id="btn-resume">ادامه</button>
                        <button class="btn btn-danger" id="btn-cancel">لغو + بازگشت بودجه</button>
                    <?php endif; ?>
                    <a href="<?= url('/admin/social-trust/user/'.(int)$ad->advertiser_id) ?>"
                       class="btn btn-outline-info">Trust تبلیغ‌دهنده</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- لیست اجراها -->
<div class="card">
    <div class="card-header fw-bold">لیست اجراها (<?= count($executions) ?>)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>اجراکننده</th><th>Trust</th><th>امتیاز</th><th>تصمیم</th><th>زمان فعال</th><th>فلگ</th><th>تاریخ</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($executions)): ?>
                <tr><td colspan="9" class="text-center py-3 text-muted">هنوز اجرایی ثبت نشده</td></tr>
            <?php else: ?>
                <?php foreach ($executions as $ex): ?>
                    <?php $d = $ex->decision ?? ''; ?>
                    <tr>
                        <td class="small text-muted"><?= (int)$ex->id ?></td>
                        <td class="small"><?= e($ex->executor_name??'') ?></td>
                        <td><span class="badge bg-<?= ($ex->trust_score??50)>=60?'success':(($ex->trust_score??50)>=40?'warning':'danger') ?>"><?= (int)($ex->trust_score??50) ?></span></td>
                        <td class="small fw-bold text-<?= ($ex->task_score??0)>=70?'success':(($ex->task_score??0)>=40?'warning':'danger') ?>">
                            <?= number_format($ex->task_score??0,1) ?>
                        </td>
                        <td><span class="badge bg-<?= $d==='approved'?'success':($d==='rejected'?'danger':'warning') ?>">
                            <?= $d==='approved'?'تأیید':($d==='rejected'?'رد':'در انتظار') ?>
                        </span></td>
                        <td class="small"><?= (int)($ex->active_time??0) ?>s</td>
                        <td><?= $ex->flag_review ? '<span class="badge bg-danger">فلگ</span>' : '' ?></td>
                        <td class="text-muted small"><?= e(substr($ex->created_at??'',0,10)) ?></td>
                        <td><a href="<?= url('/admin/social-executions/'.(int)$ex->id) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="material-icons" style="font-size:14px;">open_in_new</i>
                        </a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const csrf  = '<?= csrf_token() ?>';
const adId  = <?= (int)$ad->id ?>;
const base  = '<?= url('/admin/social-tasks') ?>';

function adminPost(url, body={}) {
    return fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}).then(r=>r.json());
}

document.getElementById('btn-approve')?.addEventListener('click', async()=>{
    if(!confirm('تأیید شود؟')) return;
    const d = await adminPost(`${base}/${adId}/approve`);
    d.success ? location.reload() : alert(d.message);
});
document.getElementById('btn-reject')?.addEventListener('click', async()=>{
    const reason = prompt('دلیل رد:'); if(!reason) return;
    const d = await adminPost(`${base}/${adId}/reject`,{reason});
    d.success ? location.reload() : alert(d.message);
});
document.getElementById('btn-pause')?.addEventListener('click', async()=>{
    const d = await adminPost(`${base}/${adId}/pause`);
    d.success ? location.reload() : alert(d.message);
});
document.getElementById('btn-resume')?.addEventListener('click', async()=>{
    const d = await adminPost(`${base}/${adId}/resume`);
    d.success ? location.reload() : alert(d.message);
});
document.getElementById('btn-cancel')?.addEventListener('click', async()=>{
    if(!confirm('لغو شود و بودجه باقیمانده برگشت داده شود؟')) return;
    const d = await adminPost(`${base}/${adId}/cancel`);
    d.success ? (alert(d.message), location.reload()) : alert(d.message);
});
</script>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
