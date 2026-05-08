<?php
// views/admin/social-accounts/show.php
$title = 'جزئیات حساب اجتماعی';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-social-accounts.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">person</i> حساب اجتماعی #<?= e($account->id) ?></h4>
    <a href="<?= url('/admin/social-accounts') ?>" class="btn btn-outline-sm"><i class="material-icons">arrow_forward</i> بازگشت</a>
</div>

<div class="detail-grid">
    <div class="card">
        <div class="card-header"><h5>اطلاعات حساب</h5></div>
        <div class="card-body">
            <div class="detail-row"><label>کاربر:</label><span><?= e($account->user_name ?? '') ?></span></div>
            <div class="detail-row"><label>پلتفرم:</label><span class="badge-sm platform-<?= e($account->platform) ?>"><?= e(social_platform_label($account->platform)) ?></span></div>
            <div class="detail-row"><label>نام کاربری:</label><span>@<?= e($account->username) ?></span></div>
            <div class="detail-row"><label>لینک:</label><a href="<?= e($account->profile_url) ?>" target="_blank"><?= e($account->profile_url) ?></a></div>
            <div class="detail-row"><label>فالوور:</label><span><?= number_format($account->follower_count) ?></span></div>
            <div class="detail-row"><label>فالووینگ:</label><span><?= number_format($account->following_count) ?></span></div>
            <div class="detail-row"><label>پست:</label><span><?= number_format($account->post_count) ?></span></div>
            <div class="detail-row"><label>قدمت:</label><span><?= e($account->account_age_months) ?> ماه</span></div>
            <div class="detail-row"><label>وضعیت:</label><span class="badge badge-<?= e(social_status_badge($account->status)) ?>"><?= e(social_status_label($account->status)) ?></span></div>
            <div class="detail-row"><label>تاریخ ثبت:</label><span><?= to_jalali($account->created_at) ?></span></div>
            <?php if ($account->verified_at): ?>
                <div class="detail-row"><label>تاریخ تایید:</label><span><?= to_jalali($account->verified_at) ?></span></div>
            <?php endif; ?>
            <?php if ($account->rejection_reason): ?>
                <div class="detail-row"><label>دلیل رد:</label><span class="text-danger"><?= e($account->rejection_reason) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- تاریخچه ردها -->
    <?php if ($account->rejection_history): ?>
        <div class="card">
            <div class="card-header"><h5>تاریخچه ردها</h5></div>
            <div class="card-body">
                <?php $history = json_decode($account->rejection_history, true) ?: []; ?>
                <?php foreach ($history as $h): ?>
                    <div class="history-item">
                        <div class="history-date"><?= to_jalali($h['date'] ?? '') ?></div>
                        <div class="history-reason"><?= e($h['reason'] ?? '') ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($history)): ?>
                    <p class="text-muted">تاریخچه‌ای وجود ندارد.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- عملیات -->
<?php if ($account->status === 'pending'): ?>
    <div class="card mt-15">
        <div class="card-header"><h5>عملیات</h5></div>
        <div class="card-body">
            <div class="action-buttons">
                <button class="btn btn-success" id="btnVerifyAcc"><i class="material-icons">check</i> تایید</button>
                <button class="btn btn-danger" id="btnRejectAcc"><i class="material-icons">close</i> رد</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
<?php if ($account->status === 'pending'): ?>
document.getElementById('btnVerifyAcc').addEventListener('click',function(){
    Swal.fire({title:'تایید',text:'حساب تایید شود؟',icon:'question',showCancelButton:true,confirmButtonText:'تایید',cancelButtonText:'انصراف',confirmButtonColor:'#4caf50'})
    .then(r=>{if(r.isConfirmed){fetch('<?=url('/admin/social-accounts/'.$account->id.'/verify')?>',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),800);}else notyf.error(d.message);});}});
});

document.getElementById('btnRejectAcc').addEventListener('click',function(){
    Swal.fire({title:'رد',input:'textarea',inputLabel:'دلیل رد:',showCancelButton:true,confirmButtonText:'رد',cancelButtonText:'انصراف',confirmButtonColor:'#f44336',inputValidator:v=>{if(!v)return'الزامی';}})
    .then(r=>{if(r.isConfirmed){fetch('<?=url('/admin/social-accounts/'.$account->id.'/reject')?>',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({reason:r.value,_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),800);}else notyf.error(d.message);});}});
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>