<?php
// views/admin/task-disputes/show.php
$title = 'جزئیات اختلاف';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-task-disputes.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">gavel</i> اختلاف #<?= e($dispute->id) ?></h4>
    <a href="<?= url('/admin/task-disputes') ?>" class="btn btn-outline-sm"><i class="material-icons">arrow_forward</i> بازگشت</a>
</div>

<div class="detail-grid">
    <div class="card">
        <div class="card-header"><h5>اطلاعات اختلاف</h5></div>
        <div class="card-body">
            <div class="detail-row"><label>وضعیت:</label><span class="badge badge-<?= e(task_dispute_status_badge($dispute->status)) ?>"><?= e(task_dispute_status_label($dispute->status)) ?></span></div>
            <div class="detail-row"><label>باز‌شده توسط:</label><span><?= e($dispute->opener_name ?? '') ?> (<?= $dispute->opened_by === 'executor' ? 'انجام‌دهنده' : 'سفارش‌دهنده' ?>)</span></div>
            <div class="detail-row"><label>تسک:</label><span><?= e($task->title ?? '—') ?></span></div>
            <div class="detail-row"><label>دلیل:</label><span><?= nl2br(e($dispute->reason)) ?></span></div>
            <div class="detail-row"><label>تاریخ ثبت:</label><span><?= to_jalali($dispute->created_at) ?></span></div>
            <?php if ($dispute->admin_decision): ?>
                <div class="detail-row"><label>تصمیم ادمین:</label><span><?= e($dispute->admin_decision) ?></span></div>
                <div class="detail-row"><label>جریمه:</label><span><?= number_format($dispute->penalty_amount) ?> (<?= $dispute->penalty_target === 'executor' ? 'انجام‌دهنده' : 'سفارش‌دهنده' ?>)</span></div>
                <div class="detail-row"><label>مالیات سایت:</label><span><?= number_format($dispute->site_tax_amount) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- مدرک -->
    <div class="card">
        <div class="card-header"><h5>مدارک</h5></div>
        <div class="card-body">
            <?php if ($execution->proof_image): ?>
                <p><strong>مدرک تسک:</strong></p>
                <img src="<?= url('/file/view/task-proofs/' . basename($execution->proof_image)) ?>" alt="مدرک تسک" class="proof-img">
            <?php endif; ?>

            <?php if ($dispute->evidence_image): ?>
                <p class="mt-15"><strong>مدرک اعتراض:</strong></p>
                <img src="<?= url('/file/view/dispute-evidence/' . basename($dispute->evidence_image)) ?>" alt="مدرک اعتراض" class="proof-img">
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- فرم داوری -->
<?php if (\in_array($dispute->status, ['open', 'under_review'])): ?>
    <div class="card mt-20">
        <div class="card-header"><h5><i class="material-icons">admin_panel_settings</i> داوری</h5></div>
        <div class="card-body">
            <div class="form-group">
                <label>توضیح تصمیم <span class="required">*</span></label>
                <textarea id="decisionText" class="form-control" rows="3" placeholder="توضیح دهید..."></textarea>
            </div>
            <div class="form-group">
                <label>مبلغ جریمه (اختیاری)</label>
                <input type="number" id="penaltyAmount" class="form-control" min="0" step="0.01" value="0">
            </div>

            <div class="action-buttons mt-15">
                <button class="btn btn-success" id="btnForExecutor">
                    <i class="material-icons">person</i> به نفع انجام‌دهنده (پرداخت پاداش)
                </button>
                <button class="btn btn-primary" id="btnForAdvertiser">
                    <i class="material-icons">store</i> به نفع سفارش‌دهنده (رد تسک)
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
<?php if (\in_array($dispute->status, ['open', 'under_review'])): ?>
function resolveDispute(endpoint) {
    const decision = document.getElementById('decisionText').value;
    const penalty = parseFloat(document.getElementById('penaltyAmount').value) || 0;

    if (!decision) { notyf.error('توضیح تصمیم الزامی است.'); return; }

    fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
        body: JSON.stringify({ decision, penalty_amount: penalty, _csrf_token: '<?= csrf_token() ?>' })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { notyf.success(d.message); setTimeout(() => location.reload(), 1000); }
        else notyf.error(d.message);
    })
    .catch(() => notyf.error('خطا'));
}

document.getElementById('btnForExecutor').addEventListener('click', () => {
    Swal.fire({ title: 'تایید', text: 'به نفع انجام‌دهنده حل شود و پاداش پرداخت شود؟', icon: 'question', showCancelButton: true, confirmButtonText: 'بله', cancelButtonText: 'انصراف', confirmButtonColor: '#4caf50' })
    .then(r => { if (r.isConfirmed) resolveDispute('<?= url('/admin/task-disputes/' . $dispute->id . '/resolve-executor') ?>'); });
});

document.getElementById('btnForAdvertiser').addEventListener('click', () => {
    Swal.fire({ title: 'تایید', text: 'به نفع سفارش‌دهنده حل شود و تسک رد شود؟', icon: 'question', showCancelButton: true, confirmButtonText: 'بله', cancelButtonText: 'انصراف', confirmButtonColor: '#2196f3' })
    .then(r => { if (r.isConfirmed) resolveDispute('<?= url('/admin/task-disputes/' . $dispute->id . '/resolve-advertiser') ?>'); });
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>