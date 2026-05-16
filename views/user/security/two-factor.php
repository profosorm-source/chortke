<?php
// views/user/security/two-factor.php
/** @var bool $is_enabled */
/** @var string $secret */
/** @var string $qr_code_url */
ob_start();
?>

<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h4 class="fw-bold">
                <span class="material-icons align-middle text-primary">security</span>
                احراز هویت دو مرحله‌ای (2FA)
            </h4>
            <p class="text-muted">افزایش امنیت حساب با استفاده از Google Authenticator یا Authy</p>
        </div>
        <div class="text-md-end">
            <a href="<?= url('/settings/security') ?>" class="btn btn-outline-secondary btn-sm">
                <span class="material-icons align-middle">arrow_back</span>
                بازگشت به تنظیمات امنیتی
            </a>
        </div>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">

        <?php if ($is_enabled): ?>
        <!-- ─── 2FA فعال است ─── -->
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-5">
                <span class="material-icons text-success" style="font-size:80px">verified_user</span>
                <h4 class="mt-3 text-success">احراز هویت دو مرحله‌ای فعال است ✅</h4>
                <p class="text-muted">حساب شما با لایه امنیتی اضافی محافظت می‌شود.</p>

                <hr class="my-4">

                <h5 class="mb-3">غیرفعال کردن 2FA</h5>
                <p class="text-muted small">برای غیرفعال کردن، رمز عبور فعلی خود را وارد کنید.</p>

                <form id="disable-2fa-form" class="mx-auto" style="max-width:360px">
                    <?= csrf_field() ?>
                    <div class="input-group mb-3">
                        <input type="password" name="password" id="disable-pass"
                               class="form-control text-center" placeholder="رمز عبور فعلی" required>
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="togglePassVis('disable-pass', this)">
                            <span class="material-icons" style="font-size:18px">visibility</span>
                        </button>
                    </div>
                    <button type="submit" class="btn btn-danger w-100" id="disable-btn">
                        <span class="material-icons align-middle">lock_open</span>
                        غیرفعال کردن
                    </button>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- ─── 2FA غیرفعال است ─── -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">

                <div class="text-center mb-4">
                    <span class="material-icons text-warning" style="font-size:80px">shield</span>
                    <h4 class="mt-3">2FA غیرفعال است</h4>
                </div>

                <!-- مراحل راهنما -->
                <div class="alert alert-info">
                    <strong>نحوه فعال‌سازی:</strong>
                    <ol class="mb-0 mt-2">
                        <li>اپلیکیشن <strong>Google Authenticator</strong> یا <strong>Authy</strong> را نصب کنید</li>
                        <li>QR Code زیر را اسکن کنید</li>
                        <li>کد ۶ رقمی نمایش داده‌شده را وارد کنید</li>
                    </ol>
                </div>

                <hr>

                <!-- QR Code -->
                <div class="text-center mb-4">
                    <h6 class="mb-3 fw-bold">QR Code</h6>
                    <div id="qrcode" class="d-inline-block border p-2 bg-white rounded shadow-sm"></div>
                    <div class="mt-3">
                        <small class="text-muted d-block mb-2">یا این کد را به صورت دستی وارد کنید:</small>
                        <div class="input-group">
                            <input type="text" class="form-control text-center font-monospace"
                                   id="secret-key" value="<?= e($secret ?? '') ?>" readonly>
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="copySecret()" title="کپی">
                                <span class="material-icons" style="font-size:18px">content_copy</span>
                            </button>
                        </div>
                    </div>
                </div>

                <hr>

                <!-- فرم فعالسازی -->
                <form id="enable-2fa-form">
                    <?= csrf_field() ?>
                    <h6 class="mb-3 text-center fw-bold">فعال‌سازی</h6>
                    <div class="mb-3">
                        <label class="form-label">کد ۶ رقمی از اپلیکیشن</label>
                        <input type="text" name="code" id="enable-code"
                               class="form-control form-control-lg text-center"
                               placeholder="۱۲۳۴۵۶" maxlength="6"
                               inputmode="numeric" pattern="[0-9]{6}"
                               autocomplete="one-time-code" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100" id="enable-btn">
                        <span class="material-icons align-middle">check_circle</span>
                        فعال‌سازی
                    </button>
                </form>

            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Modal کدهای بازیابی -->
<div class="modal fade" id="recoveryModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="material-icons align-middle text-warning">vpn_key</span>
                    کدهای بازیابی
                </h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong>⚠️ مهم:</strong> این کدها را در جای امنی ذخیره کنید.
                    اگر به گوشی دسترسی نداشتید با این کدها وارد می‌شوید. هر کد فقط یک بار قابل استفاده است.
                </div>
                <div class="row g-2" id="recovery-codes-list"></div>
                <div class="text-center mt-3">
                    <button class="btn btn-outline-primary btn-sm" onclick="downloadCodes()">
                        <span class="material-icons align-middle" style="font-size:16px">download</span>
                        دانلود کدها
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" onclick="confirmSaved()">
                    ذخیره کردم ✓
                </button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $cspNonce ?? '' ?>">
// BUG FIX 12: استفاده از Vanilla JS به جای jQuery
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
    || '<?= csrf_token() ?>';
let recoveryCodes = [];
let recoveryModal;

document.addEventListener('DOMContentLoaded', () => {
    recoveryModal = new bootstrap.Modal(document.getElementById('recoveryModal'));
});

function togglePassVis(id, btn) {
    const f = document.getElementById(id);
    const icon = btn.querySelector('.material-icons');
    f.type = f.type === 'password' ? 'text' : 'password';
    icon.textContent = f.type === 'password' ? 'visibility' : 'visibility_off';
}

function copySecret() {
    const val = document.getElementById('secret-key').value;
    navigator.clipboard.writeText(val).then(() => notyf.success('کد کپی شد'));
}

// ─── فعال‌سازی 2FA ───────────────────────────────────────────
const enableForm = document.getElementById('enable-2fa-form');
if (enableForm) {
    enableForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('enable-btn');
        const code = document.getElementById('enable-code').value.trim();

        if (!/^\d{6}$/.test(code)) {
            notyf.error('کد باید ۶ رقم باشد');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال فعال‌سازی...';

        try {
            // BUG FIX 5: URL صحیح /two-factor/enable
            const res = await fetch('<?= url('/two-factor/enable') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: new URLSearchParams(new FormData(enableForm)),
            });
            const data = await res.json();

            if (data.success) {
                recoveryCodes = data.recovery_codes || [];
                showRecoveryCodes(recoveryCodes);
                notyf.success(data.message || '2FA فعال شد');
            } else {
                notyf.error(data.message || 'خطا در فعال‌سازی');
                btn.disabled = false;
                btn.innerHTML = '<span class="material-icons align-middle">check_circle</span> فعال‌سازی';
                document.getElementById('enable-code').value = '';
                document.getElementById('enable-code').focus();
            }
        } catch (err) {
            notyf.error('خطای شبکه');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-icons align-middle">check_circle</span> فعال‌سازی';
        }
    });
}

// ─── غیرفعال‌سازی 2FA ──────────────────────────────────────
const disableForm = document.getElementById('disable-2fa-form');
if (disableForm) {
    disableForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!confirm('آیا مطمئن هستید؟ 2FA غیرفعال خواهد شد.')) return;

        const btn = document.getElementById('disable-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال غیرفعال‌سازی...';

        try {
            // BUG FIX 6: URL صحیح /two-factor/disable
            const res = await fetch('<?= url('/two-factor/disable') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: new URLSearchParams(new FormData(disableForm)),
            });
            const data = await res.json();

            if (data.success) {
                notyf.success(data.message || '2FA غیرفعال شد');
                setTimeout(() => location.reload(), 1200);
            } else {
                notyf.error(data.message || 'خطا');
                btn.disabled = false;
                btn.innerHTML = '<span class="material-icons align-middle">lock_open</span> غیرفعال کردن';
            }
        } catch (err) {
            notyf.error('خطای شبکه');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-icons align-middle">lock_open</span> غیرفعال کردن';
        }
    });
}

// ─── نمایش کدهای بازیابی ─────────────────────────────────
function showRecoveryCodes(codes) {
    const list = document.getElementById('recovery-codes-list');
    list.innerHTML = codes.map(c => `
        <div class="col-6">
            <div class="p-2 bg-light border rounded text-center">
                <code class="text-dark fw-bold">${c}</code>
            </div>
        </div>
    `).join('');
    recoveryModal.show();
}

function downloadCodes() {
    const text = 'کدهای بازیابی چرتکه\n' + new Date().toLocaleDateString('fa-IR') + '\n\n' + recoveryCodes.join('\n');
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(new Blob([text], {type: 'text/plain'})),
        download: 'chortke-recovery-codes.txt',
    });
    a.click();
}

function confirmSaved() {
    recoveryModal.hide();
    setTimeout(() => location.reload(), 500);
}
</script>

<!-- QR Code Library & Local Generation (Local Privacy Protection) -->
<script src="<?= asset('assets/vendor/qrcodejs/qrcode.min.js') ?>" nonce="<?= $cspNonce ?? '' ?>"></script>
<script nonce="<?= $cspNonce ?? '' ?>">
document.addEventListener('DOMContentLoaded', () => {
    const qrContainer = document.getElementById('qrcode');
    if (qrContainer) {
        new QRCode(qrContainer, {
            text: <?= json_encode($qr_code_url ?? '') ?>,
            width: 200,
            height: 200,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
