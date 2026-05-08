<?php
$layout      = 'admin';
$user        = $user        ?? null;
$trust       = $trust       ?? 50;
$weekly      = $weekly      ?? [];
$history     = $history     ?? [];
$restriction = $restriction ?? ['level'=>'clean','task_ratio'=>1,'reward_ratio'=>1];
ob_start();
if (!$user) { redirect(url('/admin/social-trust')); exit; }
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0">Trust کاربر: <?= e($user->full_name??'') ?></h4>
        <small class="text-muted"><?= e($user->email??'') ?></small>
    </div>
    <a href="<?= url('/admin/social-trust') ?>" class="btn btn-outline-secondary btn-sm">برگشت</a>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card text-center p-4">
            <div class="text-muted mb-2">Trust Score</div>
            <div class="display-4 fw-bold text-<?= $trust>=60?'success':($trust>=40?'warning':'danger') ?>"><?= $trust ?></div>
            <div class="progress mt-2" style="height:8px;">
                <div class="progress-bar bg-<?= $trust>=60?'success':($trust>=40?'warning':'danger') ?>"
                     style="width:<?= $trust ?>%"></div>
            </div>
            <div class="mt-3">
                <span class="badge bg-<?= $restriction['level']==='clean'?'success':($restriction['level']==='low'?'info':($restriction['level']==='medium'?'warning':'danger')) ?> fs-6">
                    محدودیت: <?= $restriction['level'] ?>
                </span>
                <div class="small text-muted mt-1">
                    <?= round($restriction['task_ratio']*100) ?>% تسک |
                    <?= round($restriction['reward_ratio']*100) ?>% پاداش
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-bold">آمار ۷ روز اخیر</div>
            <div class="card-body small">
                <?php foreach ([
                    'کل تسک‌ها'           => $weekly['total']??0,
                    'تسک خوب (score≥70)'  => $weekly['good_tasks']??0,
                    'رد شده'              => $weekly['rejected']??0,
                    'soft_approved'       => $weekly['soft_approved']??0,
                    'میانگین امتیاز'      => number_format($weekly['avg_score']??0,1),
                ] as $l=>$v): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-muted"><?= $l ?></span>
                        <span><?= $v ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (($weekly['rejected']??0)===0 && ($weekly['good_tasks']??0)>=5): ?>
                    <div class="alert alert-success mt-2 small mb-0">واجد شرایط بهبود Trust هفتگی</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-bold">تنظیم دستی Trust</div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label small">مقدار تغییر (مثبت/منفی)</label>
                    <input type="number" id="trust-delta" class="form-control form-control-sm" step="1" min="-100" max="100" placeholder="مثلاً +5 یا -10">
                </div>
                <div class="mb-2">
                    <label class="form-label small">دلیل <span class="text-danger">*</span></label>
                    <textarea id="trust-reason" class="form-control form-control-sm" rows="2" placeholder="الزامی..."></textarea>
                </div>
                <button id="btn-adjust" class="btn btn-warning btn-sm w-100">اعمال تغییر</button>
                <div id="adjust-result" class="mt-2 small"></div>
            </div>
        </div>
    </div>
</div>

<!-- تاریخچه تغییرات Trust -->
<div class="card">
    <div class="card-header fw-bold">تاریخچه تغییرات Trust Score</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>منبع</th><th>تغییر</th><th>جزئیات</th><th>تاریخ</th></tr>
            </thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="4" class="text-center py-3 text-muted">تاریخچه‌ای وجود ندارد</td></tr>
            <?php else: ?>
                <?php foreach ($history as $h): ?>
                    <?php $delta = (float)($h->delta??0); ?>
                    <tr>
                        <td class="small"><?= e($h->source??'') ?></td>
                        <td class="fw-bold text-<?= $delta>0?'success':'danger' ?>">
                            <?= $delta>0?'+':'' ?><?= $delta ?>
                        </td>
                        <td class="small text-muted">
                            <?php $meta = json_decode($h->meta_json??'{}', true); ?>
                            <?= e($meta['reason'] ?? ($meta['decision'] ?? '')) ?>
                        </td>
                        <td class="small text-muted"><?= e(substr($h->created_at??'',0,16)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const csrf   = '<?= csrf_token() ?>';
const userId = <?= (int)$user->id ?>;

document.getElementById('btn-adjust').addEventListener('click', async function() {
    const delta  = parseFloat(document.getElementById('trust-delta').value);
    const reason = document.getElementById('trust-reason').value.trim();
    if (!reason) { alert('دلیل الزامی است'); return; }
    if (isNaN(delta) || delta === 0) { alert('مقدار معتبر وارد کنید'); return; }
    this.disabled = true;
    const fd = new FormData();
    fd.append('delta', delta); fd.append('reason', reason);
    const res = await fetch(`<?= url('/admin/social-trust/user') ?>/${userId}/adjust`, {
        method:'POST', headers:{'X-CSRF-Token':csrf}, body: fd
    });
    const d = await res.json();
    const el = document.getElementById('adjust-result');
    el.className = d.success ? 'mt-2 small text-success' : 'mt-2 small text-danger';
    el.textContent = d.success ? `Trust جدید: ${d.new_trust}` : d.message;
    if (d.success) setTimeout(()=>location.reload(), 1500);
    this.disabled = false;
});
</script>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
