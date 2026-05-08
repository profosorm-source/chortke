<?php
// views/auth/reset-password.php
/** @var string $token */
ob_start();
?>

<div class="auth-card">
    <div class="auth-header">
        <h3>🎯 <?= e(setting('site_name', 'چرتکه')) ?></h3>
        <p>تنظیم مجدد رمز عبور</p>
    </div>

    <div class="auth-body">
        <?php
        use Core\Session;
        $session = Session::getInstance();
        ?>
        <?php if ($session->hasFlash('error')): ?>
            <div class="alert alert-danger"><?= e($session->getFlash('error')) ?></div>
        <?php endif; ?>
        <?php if ($session->hasFlash('success')): ?>
            <div class="alert alert-success"><?= e($session->getFlash('success')) ?></div>
        <?php endif; ?>

        <div class="alert alert-info small">
            <span class="material-icons align-middle" style="font-size:16px">info</span>
            رمز عبور جدید باید حداقل ۸ کاراکتر باشد.
        </div>

        <form method="POST" action="<?= url('/reset-password') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

            <div class="form-group mb-3">
                <label class="form-label">
                    <span class="material-icons align-middle" style="font-size:16px">lock</span>
                    رمز عبور جدید
                </label>
                <div class="input-group">
                    <input type="password" id="password" name="password"
                           class="form-control"
                           placeholder="حداقل ۸ کاراکتر"
                           required autofocus>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePass('password', this)" title="نمایش/مخفی">
                        <span class="material-icons" style="font-size:18px">visibility</span>
                    </button>
                </div>
            </div>

            <div class="form-group mb-4">
                <label class="form-label">
                    <span class="material-icons align-middle" style="font-size:16px">lock_outline</span>
                    تکرار رمز عبور
                </label>
                <div class="input-group">
                    <input type="password" id="password_confirm" name="password_confirm"
                           class="form-control"
                           placeholder="رمز عبور را مجدداً وارد کنید"
                           required>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePass('password_confirm', this)" title="نمایش/مخفی">
                        <span class="material-icons" style="font-size:18px">visibility</span>
                    </button>
                </div>
            </div>

            <!-- strength indicator -->
            <div class="mb-3">
                <div class="progress" style="height:6px">
                    <div id="strength-bar" class="progress-bar" style="width:0%"></div>
                </div>
                <small id="strength-label" class="text-muted"></small>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <span class="material-icons align-middle">check_circle</span>
                تنظیم رمز عبور
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
function togglePass(id, btn) {
    const f = document.getElementById(id);
    const icon = btn.querySelector('.material-icons');
    if (f.type === 'password') {
        f.type = 'text';
        icon.textContent = 'visibility_off';
    } else {
        f.type = 'password';
        icon.textContent = 'visibility';
    }
}

// Password strength
document.getElementById('password').addEventListener('input', function() {
    const val = this.value;
    const bar = document.getElementById('strength-bar');
    const lbl = document.getElementById('strength-label');
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        {w:'0%',   cls:'bg-danger',  txt:''},
        {w:'25%',  cls:'bg-danger',  txt:'ضعیف'},
        {w:'50%',  cls:'bg-warning', txt:'متوسط'},
        {w:'75%',  cls:'bg-info',    txt:'خوب'},
        {w:'100%', cls:'bg-success', txt:'قوی'},
    ];
    const lvl = levels[Math.min(score, 4)];
    bar.style.width = lvl.w;
    bar.className = 'progress-bar ' + lvl.cls;
    lbl.textContent = lvl.txt;
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.auth');
?>
