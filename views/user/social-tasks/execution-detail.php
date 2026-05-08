<?php
$layout = 'user';
$exec   = $exec ?? null;
ob_start();
if (!$exec) { redirect(url('/social-ads')); exit; }
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">assignment_ind</i> جزئیات اجرا</h4>
    <a href="<?= url('/social-ads/'.($exec->ad_id ?? '')) ?>" class="btn btn-outline-secondary btn-sm">برگشت</a>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header fw-bold">اطلاعات اجراکننده</div>
            <div class="card-body">
                <div class="mb-2"><span class="text-muted">نام:</span> <strong><?= e($exec->executor_name ?? '') ?></strong></div>
                <div class="mb-2"><span class="text-muted">Trust Score:</span>
                    <span class="badge bg-primary"><?= number_format($exec->executor_trust ?? 50, 0) ?></span>
                </div>
                <div class="mb-2"><span class="text-muted">تاریخ:</span> <?= e(substr($exec->created_at ?? '', 0, 16)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header fw-bold">نتیجه سیستم</div>
            <div class="card-body">
                <?php $d = $exec->decision ?? ''; ?>
                <div class="mb-2"><span class="text-muted">تصمیم:</span>
                    <span class="badge fs-6 bg-<?= $d==='approved'?'success':($d==='rejected'?'danger':'warning') ?>">
                        <?= $d==='approved'?'تأیید':($d==='rejected'?'رد':'در انتظار') ?>
                    </span>
                </div>
                <div class="mb-2"><span class="text-muted">امتیاز تسک:</span>
                    <strong><?= number_format($exec->task_score ?? 0, 1) ?></strong>
                </div>
                <div class="mb-2"><span class="text-muted">Trust Score:</span>
                    <?= number_format($exec->trust_score ?? 0, 0) ?>
                </div>
                <div class="mb-2"><span class="text-muted">Risk Score:</span>
                    <?= number_format($exec->risk_score ?? 0, 0) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scoring breakdown -->
<div class="card mb-3">
    <div class="card-header fw-bold">جزئیات امتیازدهی</div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <div class="col-3">
                <div class="text-muted small">Time Score</div>
                <div class="fw-bold"><?= number_format($exec->time_score ?? 0) ?></div>
            </div>
            <div class="col-3">
                <div class="text-muted small">Interaction</div>
                <div class="fw-bold"><?= number_format($exec->interaction_score ?? 0) ?></div>
            </div>
            <div class="col-3">
                <div class="text-muted small">Behavior</div>
                <div class="fw-bold"><?= number_format($exec->behavior_score ?? 0) ?></div>
            </div>
            <div class="col-3">
                <div class="text-muted small">زمان فعال</div>
                <div class="fw-bold"><?= number_format($exec->active_time ?? 0) ?> ثانیه</div>
            </div>
        </div>
    </div>
</div>

<!-- دکمه‌های تأیید/رد دستی (فقط در صورت soft_approved یا pending) -->
<?php if (in_array($d, ['soft_approved', 'pending'])): ?>
<div class="card">
    <div class="card-header fw-bold">اقدام دستی تبلیغ‌دهنده</div>
    <div class="card-body">
        <div class="d-flex gap-2">
            <button class="btn btn-success" id="btn-approve">
                <i class="material-icons align-middle" style="font-size:16px;">check</i> تأیید
            </button>
            <button class="btn btn-danger" id="btn-reject">
                <i class="material-icons align-middle" style="font-size:16px;">close</i> رد
            </button>
        </div>
        <div id="reject-form" style="display:none;" class="mt-3">
            <textarea id="reject-reason" class="form-control mb-2" rows="2" placeholder="دلیل رد..."></textarea>
            <button class="btn btn-danger btn-sm" id="btn-reject-confirm">تأیید رد</button>
        </div>
    </div>
</div>
<script>
const execId = <?= (int)$exec->id ?>;
const csrf   = '<?= csrf_token() ?>';

document.getElementById('btn-approve').addEventListener('click', async function() {
    this.disabled = true;
    const res = await fetch(`<?= url('/social-ads/execution') ?>/${execId}/approve`, {
        method:'POST', headers:{'X-CSRF-Token': csrf}
    });
    const data = await res.json();
    if (data.success) location.reload();
    else { alert(data.message); this.disabled = false; }
});

document.getElementById('btn-reject').addEventListener('click', function() {
    document.getElementById('reject-form').style.display = 'block';
});

document.getElementById('btn-reject-confirm').addEventListener('click', async function() {
    const reason = document.getElementById('reject-reason').value.trim();
    if (!reason) { alert('دلیل رد الزامی است'); return; }
    this.disabled = true;
    const fd = new FormData();
    fd.append('reason', reason);
    fd.append('_token', csrf);
    const res = await fetch(`<?= url('/social-ads/execution') ?>/${execId}/reject`, {
        method:'POST', headers:{'X-CSRF-Token': csrf}, body: fd
    });
    const data = await res.json();
    if (data.success) location.reload();
    else { alert(data.message); this.disabled = false; }
});
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
