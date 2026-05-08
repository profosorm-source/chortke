<?php
$layout       = 'admin';
$exec         = $exec         ?? null;
$behaviorData = $behaviorData ?? [];
$trustScore   = $trustScore   ?? 50;
$restriction  = $restriction  ?? ['level'=>'clean'];
ob_start();
if (!$exec) { redirect(url('/admin/social-executions')); exit; }
$d = $exec->decision ?? '';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">اجرا #<?= (int)$exec->id ?></h4>
    <a href="<?= url('/admin/social-executions') ?>" class="btn btn-outline-secondary btn-sm">برگشت</a>
</div>

<div class="row g-4 mb-4">
    <!-- اطلاعات اجرا -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-bold">اطلاعات اجراکننده</div>
            <div class="card-body small">
                <div class="mb-2"><span class="text-muted">نام:</span> <strong><?= e($exec->executor_name??'') ?></strong></div>
                <div class="mb-2"><span class="text-muted">ایمیل:</span> <?= e($exec->executor_email??'') ?></div>
                <div class="mb-2"><span class="text-muted">Trust Score:</span>
                    <span class="badge bg-<?= $trustScore>=60?'success':($trustScore>=40?'warning':'danger') ?> fs-6"><?= $trustScore ?></span>
                </div>
                <div class="mb-2"><span class="text-muted">سطح محدودیت:</span>
                    <span class="badge bg-<?= $restriction['level']==='clean'?'success':($restriction['level']==='low'?'info':($restriction['level']==='medium'?'warning':'danger')) ?>">
                        <?= $restriction['level'] ?>
                    </span>
                </div>
                <div class="mb-2"><span class="text-muted">آگهی:</span> <?= e($exec->ad_title??'') ?></div>
                <div class="mb-2"><span class="text-muted">پلتفرم:</span> <?= e($exec->platform??'') ?> / <?= e($exec->task_type??'') ?></div>
                <div class="mb-2"><span class="text-muted">IP:</span> <?= e($exec->ip_address??'—') ?></div>
                <div class="mb-2"><span class="text-muted">تاریخ:</span> <?= e($exec->created_at??'') ?></div>
            </div>
        </div>
    </div>

    <!-- نتیجه سیستم -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-bold">نتیجه سیستم</div>
            <div class="card-body small">
                <div class="mb-3 text-center">
                    <span class="badge fs-5 bg-<?= $d==='approved'?'success':($d==='rejected'?'danger':'warning') ?>">
                        <?= $d==='approved'?'تأیید شده':($d==='rejected'?'رد شده':'در انتظار') ?>
                    </span>
                </div>
                <?php foreach ([
                    'Task Score'       => number_format($exec->task_score??0,1),
                    'Time Score'       => (int)($exec->time_score??0),
                    'Interaction Score'=> (int)($exec->interaction_score??0),
                    'Behavior Score'   => (int)($exec->behavior_score??0),
                    'Trust Score'      => (int)($exec->trust_score??0),
                    'Risk Score'       => (int)($exec->risk_score??0),
                    'زمان فعال'        => (int)($exec->active_time??0).'s',
                    'دلیل تصمیم'       => e($exec->decision_reason??'—'),
                    'فلگ'              => $exec->flag_review ? '<span class="badge bg-danger">بله</span>' : 'خیر',
                ] as $label => $val): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-muted"><?= $label ?></span>
                        <span><?= $val ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Override -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-bold">Override تصمیم</div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label small fw-bold">تصمیم جدید</label>
                    <select id="override-decision" class="form-select form-select-sm">
                        <option value="approved">تأیید (approved)</option>
                        <option value="soft_approved">در انتظار (soft_approved)</option>
                        <option value="rejected">رد (rejected)</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">دلیل override <span class="text-danger">*</span></label>
                    <textarea id="override-reason" class="form-control form-control-sm" rows="2" placeholder="الزامی..."></textarea>
                </div>
                <button id="btn-override" class="btn btn-warning btn-sm w-100">اعمال Override</button>

                <hr>
                <div class="mb-2">
                    <label class="form-label small fw-bold">فلگ برای بررسی</label>
                    <input type="text" id="flag-note" class="form-control form-control-sm mb-1" placeholder="توضیح (اختیاری)">
                    <button id="btn-flag" class="btn btn-danger btn-sm w-100">فلگ کردن</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Behavior Data -->
<?php if (!empty($behaviorData)): ?>
<div class="card">
    <div class="card-header fw-bold">Behavior Signals</div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach ($behaviorData as $key => $val): ?>
                <?php if (!is_array($val)): ?>
                    <div class="col-6 col-md-3">
                        <div class="bg-light rounded p-2 small">
                            <div class="text-muted"><?= e($key) ?></div>
                            <div class="fw-bold"><?= e((string)$val) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const csrf  = '<?= csrf_token() ?>';
const execId = <?= (int)$exec->id ?>;

function adminPost(url, body={}) {
    return fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}).then(r=>r.json());
}

document.getElementById('btn-override').addEventListener('click', async function() {
    const decision = document.getElementById('override-decision').value;
    const reason   = document.getElementById('override-reason').value.trim();
    if (!reason) { alert('دلیل الزامی است'); return; }
    this.disabled = true;
    const d = await adminPost(`<?= url('/admin/social-executions') ?>/${execId}/override`, {decision, reason});
    d.success ? (alert(d.message), location.reload()) : (alert(d.message), this.disabled = false);
});

document.getElementById('btn-flag').addEventListener('click', async function() {
    const note = document.getElementById('flag-note').value.trim();
    this.disabled = true;
    const d = await adminPost(`<?= url('/admin/social-executions') ?>/${execId}/flag`, {note});
    d.success ? (alert('فلگ شد'), location.reload()) : (alert(d.message), this.disabled = false);
});
</script>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
