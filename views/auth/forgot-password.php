<?php
// views/auth/forgot-password.php
ob_start();
?>

<div class="auth-card">
    <div class="auth-header">
        <h3>🎯 <?= e(setting('site_name', 'چرتکه')) ?></h3>
        <p>بازیابی رمز عبور</p>
    </div>

    <div class="auth-body">

        <?php if (!empty($flashError)): ?>
            <div class="alert alert-danger"><?= e($flashError) ?></div>
        <?php endif; ?>

        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success"><?= e($flashSuccess) ?></div>
        <?php endif; ?>

        <div class="alert alert-info">
            <span class="material-icons align-middle" style="font-size:16px">info</span>
            لینک بازیابی رمز عبور به ایمیل شما ارسال خواهد شد.
            این لینک به مدت <strong>۱ ساعت</strong> معتبر است.
        </div>

        <form method="POST" action="<?= url('/forgot-password') ?>" id="forgot-form">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label">
                    <span class="material-icons align-middle" style="font-size:16px">email</span>
                    آدرس ایمیل
                </label>
                <input type="email" name="email" class="form-control"
                       placeholder="example@email.com"
                       value="<?= e($old['email'] ?? '') ?>"
                       required autofocus>
            </div>

            <button type="submit" class="btn btn-primary" id="submit-btn">
                <span class="material-icons align-middle">send</span>
                ارسال لینک بازیابی
            </button>
        </form>

    </div>

    <div class="auth-footer">
        <a href="<?= url('/login') ?>">
            <span class="material-icons align-middle" style="font-size:14px">arrow_back</span>
            بازگشت به صفحه ورود
        </a>
    </div>
</div>

<script>
document.getElementById('forgot-form').addEventListener('submit', function() {
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال ارسال...';
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.auth');
?>
