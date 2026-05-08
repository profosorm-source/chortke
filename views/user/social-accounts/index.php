<?php
// views/user/social-accounts/index.php
$title = 'حساب‌های اجتماعی';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-social-accounts.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">share</i> حساب‌های اجتماعی من</h4>
    <a href="<?= url('/social-accounts/create') ?>" class="btn btn-primary btn-sm">
        <i class="material-icons">add</i> ثبت حساب جدید
    </a>
</div>

<!-- راهنما -->
<div class="alert-box alert-info">
    <i class="material-icons">info</i>
    <div>
        <strong>توجه:</strong> برای انجام تسک‌های شبکه‌های اجتماعی، ابتدا باید حساب خود را ثبت و توسط مدیریت تایید شود.
        حساب باید حداقل ۳ ماه قدمت و تعداد پست کافی داشته باشد.
    </div>
</div>

<?php if (empty($accounts)): ?>
    <div class="empty-state">
        <i class="material-icons">person_add</i>
        <h5>هنوز حسابی ثبت نکرده‌اید</h5>
        <p>برای شروع انجام تسک‌ها، حساب اجتماعی خود را ثبت کنید.</p>
        <a href="<?= url('/social-accounts/create') ?>" class="btn btn-primary">ثبت حساب جدید</a>
    </div>
<?php else: ?>
    <div class="cards-grid">
        <?php foreach ($accounts as $account): ?>
            <div class="social-card" data-id="<?= e($account->id) ?>">
                <div class="social-card-header">
                    <div class="platform-icon platform-<?= e($account->platform) ?>">
                        <?= e(getPlatformIcon($account->platform)) ?>
                    </div>
                    <div class="platform-info">
                        <h5><?= e(social_platform_label($account->platform)) ?></h5>
                        <span class="username">@<?= e($account->username) ?></span>
                    </div>
                    <span class="badge badge-<?= e(social_status_badge($account->status)) ?>">
                        <?= e(social_status_label($account->status)) ?>
                    </span>
                </div>

                <div class="social-card-body">
                    <div class="stat-row">
                        <div class="stat-item">
                            <span class="stat-value"><?= number_format($account->follower_count) ?></span>
                            <span class="stat-label">فالوور</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= number_format($account->following_count) ?></span>
                            <span class="stat-label">فالووینگ</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= number_format($account->post_count) ?></span>
                            <span class="stat-label">پست</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= e($account->account_age_months) ?> ماه</span>
                            <span class="stat-label">قدمت</span>
                        </div>
                    </div>

                    <?php if ($account->status === 'rejected' && $account->rejection_reason): ?>
                        <div class="alert-box alert-danger mt-10">
                            <i class="material-icons">error</i>
                            <span>دلیل رد: <?= e($account->rejection_reason) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="social-card-footer">
                    <a href="<?= e($account->profile_url) ?>" target="_blank" class="btn btn-outline-sm">
                        <i class="material-icons">open_in_new</i> مشاهده پروفایل
                    </a>

                    <?php if ($account->status !== 'verified'): ?>
                        <a href="<?= url('/social-accounts/' . $account->id . '/edit') ?>" class="btn btn-outline-sm btn-warning-outline">
                            <i class="material-icons">edit</i> ویرایش
                        </a>
                    <?php endif; ?>

                    <button class="btn btn-outline-sm btn-danger-outline btn-delete-social"
                            data-id="<?= e($account->id) ?>"
                            data-name="<?= e($account->username) ?>">
                        <i class="material-icons">delete</i> حذف
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-delete-social').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const name = this.dataset.name;

        Swal.fire({
            title: 'حذف حساب',
            text: `آیا از حذف حساب @${name} مطمئن هستید؟`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'بله، حذف شود',
            cancelButtonText: 'انصراف',
            confirmButtonColor: '#f44336'
        }).then(result => {
            if (result.isConfirmed) {
                fetch(`<?= url('/social-accounts') ?>/${id}/delete`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                    },
                    body: JSON.stringify({ _csrf_token: '<?= csrf_token() ?>' })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        notyf.success(data.message);
                        document.querySelector(`.social-card[data-id="${id}"]`).remove();
                    } else {
                        notyf.error(data.message);
                    }
                })
                .catch(() => notyf.error('خطا در ارتباط'));
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);

function getPlatformIcon($platform) {
    $icons = [
        'instagram' => '<i class="material-icons">camera_alt</i>',
        'youtube'   => '<i class="material-icons">play_circle</i>',
        'telegram'  => '<i class="material-icons">send</i>',
        'tiktok'    => '<i class="material-icons">music_note</i>',
        'twitter'   => '<i class="material-icons">tag</i>',
    ];
    return $icons[$platform] ?? '<i class="material-icons">share</i>';
}
?>