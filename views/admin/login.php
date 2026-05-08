<?php
use Core\Session;
$session = Session::getInstance();
$errors = $session->getFlash('errors') ?? [];
$old = $session->getFlash('old') ?? [];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود مدیریت | <?= e(setting('site_name', 'چرتکه')) ?></title>
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/material-icons.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <style>
        :root {
            --gold:#F0B90B; --gold-bright:#FCD535; --gold-glow:rgba(240,185,11,0.35);
            --bg-deep:#0B0E11; --bg-card:#1E2329; --border:#2B3139;
            --text-primary:#EAECEF; --text-secondary:#B7BDC6; --text-muted:#848E9C;
            --green:#0ECB81; --red:#F6465D;
        }
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%;font-family:'Vazirmatn',sans-serif;background:var(--bg-deep);color:var(--text-primary);direction:rtl}
        body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(240,185,11,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(240,185,11,0.03) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;z-index:0}
        body::after{content:'';position:fixed;top:-20%;left:50%;transform:translateX(-50%);width:600px;height:600px;background:radial-gradient(circle,rgba(240,185,11,0.08) 0%,transparent 70%);pointer-events:none;z-index:0}
        .login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;position:relative;z-index:1}
        .login-container{width:100%;max-width:440px}
        .login-brand{text-align:center;margin-bottom:32px}
        .login-brand-icon{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,var(--gold),var(--gold-bright));font-size:26px;font-weight:800;color:var(--bg-deep);box-shadow:0 0 32px var(--gold-glow);margin-bottom:16px}
        .login-brand h1{font-size:22px;font-weight:800;color:var(--text-primary);margin:0 0 6px}
        .login-brand p{font-size:13px;color:var(--text-muted);margin:0}
        .login-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:32px;box-shadow:0 8px 40px rgba(0,0,0,0.4);position:relative;overflow:hidden}
        .login-card::before{content:'';position:absolute;top:0;right:0;left:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),var(--gold-bright),transparent)}
        .login-card-title{font-size:17px;font-weight:700;color:var(--text-primary);margin:0 0 6px}
        .login-card-sub{font-size:12px;color:var(--text-muted);margin:0 0 28px}
        .form-group{margin-bottom:20px}
        .form-label{display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px}
        .input-wrap{position:relative}
        .input-icon{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:18px!important;pointer-events:none}
        .form-control{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;color:var(--text-primary);font-family:'Vazirmatn',sans-serif;font-size:14px;padding:11px 42px 11px 14px;outline:none;transition:all 0.25s}
        .form-control:focus{border-color:var(--gold);background:rgba(240,185,11,0.04);box-shadow:0 0 0 3px rgba(240,185,11,0.12)}
        .form-control::placeholder{color:var(--text-muted)}
        .form-control.is-invalid{border-color:var(--red)}
        .pass-toggle{position:absolute;left:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;font-size:18px!important;transition:color 0.2s;font-family:'Material Icons'}
        .pass-toggle:hover{color:var(--text-secondary)}
        .form-error{font-size:11px;color:var(--red);margin-top:5px;display:flex;align-items:center;gap:4px}
        .alert-error{background:rgba(246,70,93,0.1);border:1px solid rgba(246,70,93,0.25);border-radius:10px;padding:12px 16px;color:var(--red);font-size:13px;display:flex;align-items:center;gap:10px;margin-bottom:24px}
        .btn-submit{width:100%;background:linear-gradient(135deg,var(--gold),var(--gold-bright));color:var(--bg-deep);border:none;border-radius:10px;font-family:'Vazirmatn',sans-serif;font-size:15px;font-weight:700;padding:13px;cursor:pointer;transition:all 0.25s;box-shadow:0 4px 20px var(--gold-glow);display:flex;align-items:center;justify-content:center;gap:8px;margin-top:8px}
        .btn-submit:hover{box-shadow:0 6px 28px var(--gold-glow);transform:translateY(-1px)}
        .btn-submit:active{transform:translateY(0)}
        .security-notice{display:flex;align-items:center;gap:8px;background:rgba(14,203,129,0.06);border:1px solid rgba(14,203,129,0.2);border-radius:8px;padding:10px 14px;font-size:11px;color:var(--green);margin-top:20px}
        .login-footer{text-align:center;font-size:12px;color:var(--text-muted);margin-top:24px}
        .login-footer a{color:var(--gold);text-decoration:none}
        .login-footer a:hover{text-decoration:underline}
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-container">
        <div class="login-brand">
            <?php $adminLogo = site_logo('main'); ?>
            <?php if ($adminLogo): ?>
                <img src="<?= e($adminLogo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>" style="height:56px;object-fit:contain;margin-bottom:16px;display:block;margin-right:auto;margin-left:auto;">
            <?php else: ?>
                <div class="login-brand-icon">چ</div>
            <?php endif; ?>
            <h1><?= e(setting('site_name', 'چرتکه')) ?></h1>
            <p>پنل مدیریت سیستم</p>
        </div>
        <div class="login-card">
            <h2 class="login-card-title">ورود به پنل مدیریت</h2>
            <p class="login-card-sub">لطفاً اطلاعات حساب مدیریت خود را وارد کنید</p>
            <?php if (!empty($errors) && isset($errors['general'])): ?>
            <div class="alert-error">
                <span class="material-icons" style="font-size:18px!important;flex-shrink:0">error_outline</span>
                <span><?= e($errors['general']) ?></span>
            </div>
            <?php endif; ?>
            <form method="POST" action="<?= url('/admin/login') ?>">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label">ایمیل یا نام کاربری</label>
                    <div class="input-wrap">
                        <span class="material-icons input-icon">person_outline</span>
                        <input type="text" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" placeholder="admin@example.com" value="<?= e($old['email'] ?? '') ?>" autocomplete="username" autofocus>
                    </div>
                    <?php if (isset($errors['email'])): ?>
                    <div class="form-error"><span class="material-icons" style="font-size:13px!important">error</span><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">رمز عبور</label>
                    <div class="input-wrap">
                        <span class="material-icons input-icon">lock_outline</span>
                        <input type="password" name="password" id="passwordInput" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" placeholder="••••••••" autocomplete="current-password" style="padding-left:42px">
                        <button type="button" class="pass-toggle material-icons" id="passToggle" onclick="togglePass()">visibility_off</button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                    <div class="form-error"><span class="material-icons" style="font-size:13px!important">error</span><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn-submit">
                    <span class="material-icons" style="font-size:18px!important">login</span>
                    ورود به پنل مدیریت
                </button>
                <div class="security-notice">
                    <span class="material-icons" style="font-size:15px!important;flex-shrink:0">shield</span>
                    <span>اتصال امن · تمام فعالیت‌ها ثبت می‌شود</span>
                </div>
            </form>
        </div>
        <div class="login-footer">
            <a href="<?= url('/dashboard') ?>">← بازگشت به سایت</a>
            &nbsp;·&nbsp;
            <?= date('Y') ?> © <?= e(setting('site_name', 'چرتکه')) ?>
        </div>
    </div>
</div>
<script>
function togglePass(){const i=document.getElementById('passwordInput'),b=document.getElementById('passToggle');if(i.type==='password'){i.type='text';b.textContent='visibility'}else{i.type='password';b.textContent='visibility_off'}}
</script>
</body>
</html>
