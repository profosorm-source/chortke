<?php
// views/user/security/verify-2fa.php
// صفحه تأیید 2FA در هنگام ورود - بدون نیاز به layout کامل
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title>تأیید هویت دو مرحله‌ای | <?= e(setting('site_name', 'چرتکه')) ?></title>
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/chortke.css') ?>">
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); min-height: 100vh; }
        .verify-card { background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 400px; width: 100%; }
        .code-input { font-size: 2rem; letter-spacing: 0.5rem; text-align: center; font-weight: bold; }
        .otp-digits { display: flex; gap: 8px; justify-content: center; }
        .otp-digit { width: 48px; height: 56px; font-size: 1.5rem; text-align: center; border: 2px solid #dee2e6; border-radius: 12px; font-weight: bold; transition: border-color .2s; }
        .otp-digit:focus { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,.2); outline: none; }
        .otp-digit.filled { border-color: #198754; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-4">

<div class="verify-card p-4 p-md-5">

    <!-- آیکون و عنوان -->
    <div class="text-center mb-4">
        <span class="material-icons text-primary" style="font-size:64px">security</span>
        <h4 class="mt-2 fw-bold">تأیید هویت دو مرحله‌ای</h4>
        <p class="text-muted small">کد ۶ رقمی از Google Authenticator را وارد کنید</p>
    </div>

    <!-- فرم OTP با ورودی‌های جداگانه -->
    <form id="verify-form">
        <?= csrf_field() ?>
        <input type="hidden" name="code" id="code-hidden">

        <div class="otp-digits mb-4" id="otp-digits">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" class="otp-digit" maxlength="1"
                   inputmode="numeric" pattern="[0-9]"
                   autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                   <?= $i === 0 ? 'autofocus' : '' ?>>
            <?php endfor; ?>
        </div>

        <div id="error-msg" class="alert alert-danger d-none mb-3 text-center"></div>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3" id="submit-btn" disabled>
            <span class="material-icons align-middle">check_circle</span>
            تأیید ورود
        </button>
    </form>

    <!-- کد بازیابی -->
    <div class="text-center mb-3">
        <button class="btn btn-link btn-sm text-muted" type="button"
                data-bs-toggle="collapse" data-bs-target="#recovery-section">
            دسترسی به Google Authenticator ندارید؟
        </button>
        <div class="collapse mt-2" id="recovery-section">
            <p class="small text-muted">کد بازیابی خود را وارد کنید:</p>
            <input type="text" id="recovery-code" class="form-control text-center font-monospace"
                   placeholder="XXXXXXXX" maxlength="8" autocomplete="off">
            <button class="btn btn-outline-secondary btn-sm mt-2 w-100" onclick="useRecovery()">
                استفاده از کد بازیابی
            </button>
        </div>
    </div>

    <!-- خروج -->
    <div class="text-center">
        <a href="<?= url('/logout') ?>" class="text-muted small text-decoration-none">
            <span class="material-icons align-middle" style="font-size:14px">logout</span>
            انصراف و خروج از سیستم
        </a>
    </div>
</div>

<script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
<script>
// BUG FIX 12: Vanilla JS - بدون jQuery
const notyf = new Notyf({ duration: 4000, position: { x: 'center', y: 'top' } });
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

// ─── OTP Input handling ──────────────────────────────────────
const digits   = document.querySelectorAll('.otp-digit');
const hidden   = document.getElementById('code-hidden');
const submitBtn = document.getElementById('submit-btn');

digits.forEach((input, idx) => {
    input.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !input.value && idx > 0) {
            digits[idx - 1].focus();
            digits[idx - 1].value = '';
            digits[idx - 1].classList.remove('filled');
        }
    });

    input.addEventListener('input', e => {
        // فقط اعداد
        input.value = input.value.replace(/\D/g, '').slice(-1);
        if (input.value) {
            input.classList.add('filled');
            if (idx < 5) digits[idx + 1].focus();
        } else {
            input.classList.remove('filled');
        }
        syncHidden();
    });

    // پیست کردن کل کد
    input.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        pasted.split('').forEach((ch, i) => {
            if (digits[i]) {
                digits[i].value = ch;
                digits[i].classList.add('filled');
            }
        });
        if (pasted.length === 6) {
            digits[5].focus();
            syncHidden();
            submitBtn.click();
        }
    });
});

function syncHidden() {
    const val = Array.from(digits).map(d => d.value).join('');
    hidden.value = val;
    submitBtn.disabled = val.length !== 6;
}

// ─── Submit ──────────────────────────────────────────────────
document.getElementById('verify-form').addEventListener('submit', async e => {
    e.preventDefault();
    await submitCode(hidden.value);
});

async function submitCode(code) {
    const btn = submitBtn;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال تأیید...';
    document.getElementById('error-msg').classList.add('d-none');

    try {
        // BUG FIX 7: URL صحیح /verify-2fa
        const res = await fetch('<?= url('/verify-2fa') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: 'code=' + encodeURIComponent(code)
                + '&_token=' + encodeURIComponent(csrfToken),
        });

        const data = await res.json();

        if (data.success) {
            notyf.success(data.message || 'ورود موفق');
            // BUG FIX 10: فیلد redirect درست است (نه data.redirect)
            setTimeout(() => {
                window.location.href = data.redirect || '<?= url('/dashboard') ?>';
            }, 800);
        } else {
            const errEl = document.getElementById('error-msg');
            errEl.textContent = data.message || 'کد نامعتبر است';
            errEl.classList.remove('d-none');
            // پاک کردن inputs
            digits.forEach(d => { d.value = ''; d.classList.remove('filled'); });
            hidden.value = '';
            digits[0].focus();
            btn.disabled = true;
            btn.innerHTML = '<span class="material-icons align-middle">check_circle</span> تأیید ورود';
        }
    } catch (err) {
        notyf.error('خطای شبکه. لطفاً دوباره امتحان کنید.');
        btn.disabled = false;
        btn.innerHTML = '<span class="material-icons align-middle">check_circle</span> تأیید ورود';
    }
}

// ─── Recovery code ───────────────────────────────────────────
async function useRecovery() {
    const code = document.getElementById('recovery-code').value.trim().toUpperCase();
    if (code.length < 6) {
        notyf.error('کد بازیابی نامعتبر است');
        return;
    }
    await submitCode(code);
}
</script>
</body>
</html>
