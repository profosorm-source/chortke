<?php
// views/user/social-accounts/create.php
$title = 'ثبت حساب اجتماعی';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-social-accounts.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">person_add</i> ثبت حساب اجتماعی جدید</h4>
    <a href="<?= url('/social-accounts') ?>" class="btn btn-outline-sm">
        <i class="material-icons">arrow_forward</i> بازگشت
    </a>
</div>

<div class="card">
    <div class="card-header">
        <h5>اطلاعات حساب</h5>
    </div>
    <div class="card-body">
        <form action="<?= url('/social-accounts/store') ?>" method="POST" id="socialForm">
            <?= csrf_field() ?>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>پلتفرم <span class="required">*</span></label>
                    <select name="platform" id="platform" class="form-control" required>
                        <option value="">انتخاب کنید...</option>
                        <?php foreach ($platforms as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= old('platform') === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group col-md-6">
                    <label>نام کاربری <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" 
                           value="<?= e(old('username') ?? '') ?>"
                           placeholder="مثال: mypage123" required>
                    <small class="form-hint">بدون @ وارد کنید</small>
                </div>
            </div>

            <div class="form-group">
                <label>لینک پروفایل <span class="required">*</span></label>
                <input type="url" name="profile_url" class="form-control ltr-input"
                       value="<?= e(old('profile_url') ?? '') ?>"
                       placeholder="https://instagram.com/mypage123" required>
            </div>

            <div class="form-row">
                <div class="form-group col-md-3">
                    <label>تعداد فالوور <span class="required">*</span></label>
                    <input type="number" name="follower_count" class="form-control"
                           value="<?= e(old('follower_count') ?? '0') ?>" min="0" required>
                </div>

                <div class="form-group col-md-3">
                    <label>تعداد فالووینگ</label>
                    <input type="number" name="following_count" class="form-control"
                           value="<?= e(old('following_count') ?? '0') ?>" min="0">
                </div>

                <div class="form-group col-md-3">
                    <label>تعداد پست <span class="required">*</span></label>
                    <input type="number" name="post_count" class="form-control"
                           value="<?= e(old('post_count') ?? '0') ?>" min="0" required>
                </div>

                <div class="form-group col-md-3">
                    <label>قدمت حساب (ماه) <span class="required">*</span></label>
                    <input type="number" name="account_age_months" class="form-control"
                           value="<?= e(old('account_age_months') ?? '0') ?>" min="0" required>
                </div>
            </div>

            <!-- قوانین -->
            <div class="alert-box alert-warning mt-15">
                <i class="material-icons">warning</i>
                <div>
                    <strong>شرایط تایید حساب:</strong>
                    <ul class="rules-list">
                        <li>حساب باید حداقل <strong>۳ ماه</strong> قدمت داشته باشد</li>
                        <li>حداقل <strong>۱۰ پست</strong> در اینستاگرام/توییتر و <strong>۵ ویدیو</strong> در یوتیوب</li>
                        <li>حساب نباید فیک باشد و باید تعامل واقعی با فالوورها داشته باشد</li>
                        <li>اطلاعات وارد‌شده توسط مدیریت بررسی و تایید می‌شود</li>
                    </ul>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">send</i> ثبت و ارسال برای بررسی
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>