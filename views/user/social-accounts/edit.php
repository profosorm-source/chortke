<?php
// views/user/social-accounts/edit.php
$title = 'ویرایش حساب اجتماعی';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-social-accounts.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">edit</i> ویرایش حساب — <?= e(social_platform_label($account->platform)) ?></h4>
    <a href="<?= url('/social-accounts') ?>" class="btn btn-outline-sm">
        <i class="material-icons">arrow_forward</i> بازگشت
    </a>
</div>

<?php if ($account->status === 'rejected' && $account->rejection_reason): ?>
    <div class="alert-box alert-danger">
        <i class="material-icons">error</i>
        <div>
            <strong>دلیل رد قبلی:</strong> <?= e($account->rejection_reason) ?>
            <br><small>لطفاً اطلاعات را اصلاح و مجدداً ارسال کنید.</small>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form action="<?= url('/social-accounts/' . $account->id . '/update') ?>" method="POST">
            <?= csrf_field() ?>

            <div class="form-group">
                <label>پلتفرم</label>
                <input type="text" class="form-control" 
                       value="<?= e(social_platform_label($account->platform)) ?>" disabled>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>نام کاربری <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" 
                           value="<?= e($account->username) ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label>لینک پروفایل <span class="required">*</span></label>
                    <input type="url" name="profile_url" class="form-control ltr-input"
                           value="<?= e($account->profile_url) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-3">
                    <label>تعداد فالوور</label>
                    <input type="number" name="follower_count" class="form-control"
                           value="<?= e($account->follower_count) ?>" min="0" required>
                </div>
                <div class="form-group col-md-3">
                    <label>تعداد فالووینگ</label>
                    <input type="number" name="following_count" class="form-control"
                           value="<?= e($account->following_count) ?>" min="0">
                </div>
                <div class="form-group col-md-3">
                    <label>تعداد پست</label>
                    <input type="number" name="post_count" class="form-control"
                           value="<?= e($account->post_count) ?>" min="0" required>
                </div>
                <div class="form-group col-md-3">
                    <label>قدمت (ماه)</label>
                    <input type="number" name="account_age_months" class="form-control"
                           value="<?= e($account->account_age_months) ?>" min="0" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">save</i> ذخیره و ارسال مجدد
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>