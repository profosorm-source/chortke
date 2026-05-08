<?php
// views/admin/task-executions/show.php
$title = 'جزئیات اجرای تسک';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-task-executions.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">info</i> اجرای تسک #<?= e($execution->id) ?></h4>
    <a href="<?= url('/admin/task-executions') ?>" class="btn btn-outline-sm"><i class="material-icons">arrow_forward</i> بازگشت</a>
</div>

<div class="detail-grid">
    <!-- اطلاعات تسک -->
    <div class="card">
        <div class="card-header"><h5>اطلاعات تسک</h5></div>
        <div class="card-body">
            <div class="detail-row"><label>عنوان:</label><span><?= e($task->title ?? '—') ?></span></div>
            <div class="detail-row"><label>پلتفرم:</label><span class="badge-sm platform-<?= e($task->platform ?? '') ?>"><?= e(social_platform_label($task->platform ?? '')) ?></span></div>
            <div class="detail-row"><label>نوع:</label><span><?= e(ad_task_type_label($task->task_type ?? '')) ?></span></div>
            <div class="detail-row"><label>لینک:</label><a href="<?= sanitize_url($task->target_url ?? '') ?>" target="_blank" class="ltr-text"><?= e($task->target_url ?? '') ?></a></div>
        </div>
    </div>

    <!-- اطلاعات اجرا -->
    <div class="card">
        <div class="card-header"><h5>اطلاعات اجرا</h5></div>
        <div class="card-body">
            <div class="detail-row"><label>انجام‌دهنده:</label><span><?= e($execution->executor_name ?? '') ?> (<?= e($execution->executor_email ?? '') ?>)</span></div>
            <div class="detail-row"><label>حساب اجتماعی:</label><span><?= $execution->social_username ? '@' . e($execution->social_username) : '—' ?></span></div>
            <div class="detail-row"><label>وضعیت:</label><span class="badge badge-<?= e(task_execution_status_badge($execution->status)) ?>"><?= e(task_execution_status_label($execution->status)) ?></span></div>
            <div class="detail-row"><label>پاداش:</label><span><?= number_format($execution->reward_amount) ?> <?= $execution->reward_currency === 'usdt' ? 'تتر' : 'تومان' ?></span></div>
            <div class="detail-row"><label>پرداخت شده:</label><span><?= $execution->reward_paid ? '✅ بله' : '❌ خیر' ?></span></div>
            <div class="detail-row"><label>شروع:</label><span><?= to_jalali($execution->started_at) ?></span></div>
            <div class="detail-row"><label>ارسال مدرک:</label><span><?= $execution->submitted_at ? to_jalali($execution->submitted_at) : '—' ?></span></div>
            <div class="detail-row"><label>بررسی:</label><span><?= $execution->reviewed_at ? to_jalali($execution->reviewed_at) : '—' ?></span></div>
            <?php if ($execution->rejection_reason): ?>
                <div class="detail-row"><label>دلیل رد:</label><span class="text-danger"><?= e($execution->rejection_reason) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- مدرک -->
<?php if ($execution->proof_image): ?>
    <div class="card mt-15">
        <div class="card-header"><h5><i class="material-icons">image</i> مدرک ارسال‌شده</h5></div>
        <div class="card-body text-center">
            <img src="<?= url('/file/view/task-proofs/' . basename($execution->proof_image)) ?>" alt="مدرک" class="proof-large">
        </div>
    </div>
<?php endif; ?>

<!-- اطلاعات امنیتی -->
<div class="card mt-15">
    <div class="card-header"><h5><i class="material-icons">security</i> اطلاعات امنیتی</h5></div>
    <div class="card-body">
        <div class="detail-row"><label>IP:</label><span class="ltr-text"><?= e($execution->ip_address ?? '—') ?></span></div>
        <div class="detail-row"><label>User Agent:</label><span class="ltr-text small-text"><?= e(mb_substr($execution->user_agent ?? '', 0, 100)) ?></span></div>
        <div class="detail-row"><label>Fingerprint:</label><span class="ltr-text"><?= e($execution->device_fingerprint ?? '—') ?></span></div>
        <div class="detail-row">
            <label>امتیاز تقلب:</label>
            <span class="<?= $execution->fraud_score >= 50 ? 'text-danger' : ($execution->fraud_score > 0 ? 'text-warning' : '') ?>">
                <?= e(round($execution->fraud_score)) ?>
            </span>
        </div>
    </div>
</div>

<!-- اختلاف -->
<?php if ($dispute): ?>
    <div class="card mt-15">
        <div class="card-header"><h5><i class="material-icons">gavel</i> اختلاف</h5></div>
        <div class="card-body">
            <div class="detail-row"><label>وضعیت:</label><span class="badge badge-<?= e(task_dispute_status_badge($dispute->status)) ?>"><?= e(task_dispute_status_label($dispute->status)) ?></span></div>
            <div class="detail-row"><label>باز شده توسط:</label><span><?= $dispute->opened_by === 'executor' ? 'انجام‌دهنده' : 'تبلیغ‌دهنده' ?></span></div>
            <div class="detail-row"><label>دلیل:</label><span><?= e($dispute->reason) ?></span></div>
            <?php if ($dispute->admin_decision): ?>
                <div class="detail-row"><label>تصمیم ادمین:</label><span><?= e($dispute->admin_decision) ?></span></div>
            <?php endif; ?>
            <a href="<?= url('/admin/task-disputes/' . $dispute->id) ?>" class="btn btn-sm btn-primary mt-10">مشاهده جزئیات اختلاف</a>
        </div>
    </div>
<?php endif; ?>

<!-- عملیات ادمین -->
<?php if ($execution->status === 'submitted' || $execution->status === 'disputed'): ?>
    <div class="card mt-15">
        <div class="card-header"><h5><i class="material-icons">admin_panel_settings</i> عملیات مدیریت</h5></div>
        <div class="card-body">
            <div class="action-buttons">
                <button class="btn btn-success" id="btnAdminApprove"><i class="material-icons">check</i> تایید و پرداخت پاداش</button>
                <button class="btn btn-danger" id="btnAdminReject"><i class="material-icons">close</i> رد تسک</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
<?php if ($execution->status === 'submitted' || $execution->status === 'disputed'): ?>
document.getElementById('btnAdminApprove').addEventListener('click',function(){
    Swal.fire({title:'تایید تسک',text:'تسک تایید و پاداش پرداخت شود؟',icon:'question',showCancelButton:true,confirmButtonText:'تایید',cancelButtonText:'انصراف',confirmButtonColor:'#4caf50'})
    .then(r=>{if(r.isConfirmed){fetch('<?=url('/admin/task-executions/'.$execution->id.'/approve')?>',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),1000);}else notyf.error(d.message);});}});
});

document.getElementById('btnAdminReject').addEventListener('click',function(){
    Swal.fire({title:'رد تسک',input:'textarea',inputLabel:'دلیل رد:',showCancelButton:true,confirmButtonText:'رد',cancelButtonText:'انصراف',confirmButtonColor:'#f44336',inputValidator:v=>{if(!v)return'الزامی';}})
    .then(r=>{if(r.isConfirmed){fetch('<?=url('/admin/task-executions/'.$execution->id.'/reject')?>',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({reason:r.value,_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),1000);}else notyf.error(d.message);});}});
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>