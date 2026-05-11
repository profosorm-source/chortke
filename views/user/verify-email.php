<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تأیید ایمیل | چرتکه</title>
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/auth.css') ?>">
    <style>
        .email-badge {
            background: linear-gradient(135deg, #e0f7fa, #b2ebf2);
            border-right: 4px solid var(--primary);
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            color: #00697a;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .email-badge .material-icons { color: var(--primary); font-size: 20px; }
        .code-input {
            letter-spacing: 8px;
            font-size: 24px;
            font-family: monospace;
            text-align: center;
            text-transform: uppercase;
            border: 2px solid var(--primary) !important;
            border-radius: 10px !important;
            padding: 14px !important;
            color: #333;
        }
        .code-input:focus {
            box-shadow: 0 0 0 3px rgba(0,188,212,0.15) !important;
        }
        .resend-btn {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 13px;
            cursor: pointer;
            padding: 0;
            font-family: inherit;
        }
        .resend-btn:hover { text-decoration: underline; color: var(--primary-dark); }
        .resend-btn:disabled { color: #aaa; cursor: default; text-decoration: none; }
        .steps {
            display: flex;
            gap: 6px;
            margin-bottom: 20px;
            font-size: 12px;
            color: #aaa;
            justify-content: center;
        }
        .steps .step { display:flex; align-items:center; gap:4px; }
        .steps .step.done { color: var(--primary); }
        .steps .step .material-icons { font-size: 14px; }
        .steps .sep { color:#ddd; }
    </style>
</head>
<body>
<div class="auth-container">
    <div class="auth-card">

        <div class="auth-header">
            <h3>🎯 چرتکه</h3>
            <p>تأیید ایمیل حساب کاربری</p>
        </div>

        <div class="auth-body">

            <!-- مراحل -->
            <div class="steps">
                <span class="step done">
                    <span class="material-icons">check_circle</span> ثبت‌نام
                </span>
                <span class="sep">←</span>
                <span class="step done" style="color:var(--primary);font-weight:bold;">
                    <span class="material-icons">mark_email_unread</span> تأیید ایمیل
                </span>
                <span class="sep">←</span>
                <span class="step">
                    <span class="material-icons">login</span> ورود
                </span>
            </div>

            <?php if (!empty($flashSuccess)): ?>
                <div class="alert alert-success">
                    <span class="material-icons">check_circle</span>
                    <?= e($flashSuccess) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($flashError)): ?>
                <div class="alert alert-danger">
                    <span class="material-icons">error</span>
                    <?= e($flashError) ?>
                </div>
            <?php endif; ?>

            <!-- نشان ایمیل -->
            <?php if (!empty($email)): ?>
            <div class="email-badge">
                <span class="material-icons">email</span>
                ایمیل تأیید به <strong style="margin-right:4px;"><?= e($email) ?></strong> ارسال شد
            </div>
            <?php endif; ?>

            <!-- فرم کد -->
            <form method="POST" action="<?= url('/email/verify-code') ?>" id="codeForm">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="<?= e($email ?? '') ?>">

                <div class="form-group">
                    <label class="form-label">
                        <span class="material-icons" style="font-size:16px;vertical-align:middle;color:var(--primary);">pin</span>
                        کد تأیید
                        <small class="text-muted fw-normal" style="font-size:12px;">(۶ کاراکتر از ایمیل)</small>
                    </label>
                    <input type="text"
                           name="code"
                           id="codeField"
                           class="form-control code-input"
                           placeholder="A1B2C3"
                           maxlength="6"
                           autocomplete="off"
                           autofocus
                           required>
                </div>

                <button type="submit" class="btn btn-primary">
                    <span class="material-icons" style="font-size:18px;vertical-align:middle;">verified</span>
                    تأیید ایمیل
                </button>
            </form>

            <div class="divider"><span>یا</span></div>

            <!-- لینک مستقیم -->
            <p class="text-center text-muted" style="font-size:13px;">
                <span class="material-icons" style="font-size:15px;vertical-align:middle;">link</span>
                روی لینک داخل ایمیل کلیک کنید تا مستقیم تأیید شود
            </p>

            <hr style="border-color:#eee; margin:18px 0;">

            <!-- ارسال مجدد -->
            <div class="text-center">
                <form method="POST" action="<?= url('/email/resend-verification') ?>" id="resendForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="email" value="<?= e($email ?? '') ?>">
                    <span style="font-size:13px;color:#888;">ایمیل نرسید؟ </span>
                    <button type="submit" class="resend-btn" id="resendBtn">ارسال مجدد کد</button>
                </form>
            </div>

        </div>

        <div class="auth-footer">
            <a href="<?= url('/login') ?>" style="color:#aaa;font-size:13px;">
                <span class="material-icons" style="font-size:14px;vertical-align:middle;">arrow_forward</span>
                بازگشت به ورود
            </a>
            <span style="color:#ddd;margin:0 8px;">|</span>
            <a href="<?= url('/dashboard') ?>" style="color:#aaa;font-size:13px;">
                <span class="material-icons" style="font-size:14px;vertical-align:middle;">skip_next</span>
                بعداً تأیید می‌کنم
            </a>
        </div>

    </div>
</div>

<script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
// فقط حروف و اعداد — uppercase
document.getElementById('codeField').addEventListener('input', function() {
    this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

// کانتدون ۶۰ ثانیه برای ارسال مجدد
(function() {
    const btn = document.getElementById('resendBtn');
    let seconds = 0;
    const stored = sessionStorage.getItem('resend_ts');
    if (stored) {
        const elapsed = Math.floor((Date.now() - parseInt(stored)) / 1000);
        seconds = Math.max(0, 60 - elapsed);
    }
    if (seconds > 0) startCountdown(seconds);

    document.getElementById('resendForm').addEventListener('submit', function() {
        sessionStorage.setItem('resend_ts', Date.now().toString());
        startCountdown(60);
    });

    function startCountdown(sec) {
        btn.disabled = true;
        let s = sec;
        btn.textContent = 'ارسال مجدد (' + s + 'ث)';
        const t = setInterval(function() {
            s--;
            if (s <= 0) {
                clearInterval(t);
                btn.disabled = false;
                btn.textContent = 'ارسال مجدد کد';
            } else {
                btn.textContent = 'ارسال مجدد (' + s + 'ث)';
            }
        }, 1000);
    }
})();
</script>
</body>
</html>