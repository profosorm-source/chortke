<?php
$errors = $errors ?? [];
$old    = $old    ?? [];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود | <?= e(setting('site_name','چرتکه')) ?></title>
    
    <!-- Favicon -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>
    
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/material-icons.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">>
<link rel="stylesheet" href="<?= asset('assets/css/auth.css') ?>">
</head>
<body>
    
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <?php $__authLogo = site_logo('main'); ?>
                <?php if ($__authLogo): ?>
                    <a href="<?= url('/') ?>">
                        <img src="<?= e($__authLogo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>" style="max-height:60px;max-width:180px;object-fit:contain;margin-bottom:8px">
                    </a>
                <?php else: ?>
                    <h3>🎯 <?= e(setting('site_name','چرتکه')) ?></h3>
                <?php endif; ?>
                <p>ورود به حساب کاربری</p>
            </div>
            
            <div class="auth-body">
                <?php if (!empty($flashSuccess)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <span class="material-icons">check_circle</span>
                        <?= e($flashSuccess) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($flashError)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <span class="material-icons">error</span>
                        <?= e($flashError) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($showResendVerification)): ?>
                    <div class="alert alert-warning" style="border-radius:10px;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                            <span class="material-icons" style="color:#f59e0b;">mark_email_unread</span>
                            <strong>ایمیل تأیید نشده</strong>
                        </div>
                        <p style="font-size:13px;margin-bottom:12px;">
                            برای ورود ابتدا باید ایمیل خود را تأیید کنید.
                        </p>
                        <a href="<?= url('/email/verify-code?email=' . urlencode($resendEmail ?? '')) ?>"
                           class="btn btn-warning btn-sm" style="font-size:13px;">
                            <span class="material-icons" style="font-size:15px;vertical-align:middle;">verified</span>
                            تأیید ایمیل
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($flashWarning)): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <span class="material-icons">warning</span>
                        <?= e($flashWarning) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="<?= url('/login') ?>">
                    <?= csrf_field() ?>
                    
                    <div class="form-group">
                        <label class="form-label">ایمیل یا موبایل</label>
                        <input type="text" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                            value="<?= e($old['email'] ?? '') ?>" required autofocus>
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback" style="display: block;">
                                <?= e($errors['email'][0]) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">رمز عبور</label>
                        <input type="password" name="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" required>
                        <?php if (isset($errors['password'])): ?>
                            <div class="invalid-feedback" style="display: block;">
                                <?= e($errors['password'][0]) ?>
                            </div>
                        <?php endif; ?>
                    </div>
<?php if (!empty($captchaType)): ?>
                    <?= captcha_field($captchaType) ?>
                    <?php if ($captchaType === 'recaptcha_v2'): ?>
                        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                    <?php endif; ?>
                    <?php if (!empty($errors['captcha'])): ?>
                        <div class="text-danger" style="font-size:12px;margin-top:6px;">
                            <?= e($errors['captcha']) ?>
                        </div>
                    <?php endif; ?>
<?php endif; ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                        <label class="form-check-label" for="remember">
                            مرا به خاطر بسپار
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary mt-3">ورود</button>
            
            <div class="divider"><span>یا</span></div>
            
            <a href="<?= url('login/google') ?>" class="btn btn-outline-secondary w-100" style="border-radius:8px; border-color:#ddd; color:#555; text-decoration:none; display:flex; justify-content:center; align-items:center;">
                <img src="https://upload.wikimedia.org/wikipedia/commons/5/53/Google_%22G%22_Logo.svg" alt="Google" style="width:20px; height:20px; margin-left:10px;">
                ورود با گوگل
            </a>
        </form>
    </div>
                
                <div class="divider">
                    <span>یا</span>
                </div>
                
                <div class="text-center">
                    <a href="<?= url('/forgot-password') ?>" style="color: var(--primary);">رمز عبور خود را فراموش کرده‌اید؟</a>
                </div>
            </div>
            
            <div class="auth-footer">
                حساب کاربری ندارید؟ <a href="<?= url('/register') ?>">ثبت‌نام کنید</a>
            </div>
        </div>
    </div>
    
    <script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= asset('assets/js/app.js') ?>"></script>
<?= captcha_refresh_script() ?>
</body>