<?php
$pageTitle = $pageTitle ?? 'برداشت وجه';
$summary = $summary ?? null;
$cards = $cards ?? [];
$siteCurrency = $siteCurrency ?? 'irt';
$minWithdrawal = $minWithdrawal ?? 50000;
$old = old();
?>
<?php
$title = $title ?? 'درخواست برداشت';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-withdrawal.css') ?>">


    <!-- Header -->
    <div class="content-header">
        <h1>برداشت وجه</h1>
        <a href="<?= url('/wallet') ?>" class="btn btn-outline">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <?php if ($summary): ?>
    
    <!-- اطلاعات موجودی -->
    <div class="balance-info-card">
        <div class="balance-item">
            <div class="balance-icon">
                <i class="material-icons">account_balance_wallet</i>
            </div>
            <div class="balance-details">
                <span class="label">موجودی قابل برداشت:</span>
                <span class="amount">
                    <?php if ($siteCurrency === 'irt'): ?>
                        <?= number_format($summary->balance_irt) ?> تومان
                    <?php else: ?>
                        <?= number_format($summary->balance_usdt, 4) ?> USDT
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <!-- محدودیت‌های هوشمند بر اساس KYC و سطح -->
        <div id="withdrawalLimitsBox" class="alert alert-info d-none">
            <i class="material-icons float-end">info</i>
            <strong id="limitsProfileLabel"></strong>
            <div class="row mt-2 g-2 small" id="limitsDetail"></div>
        </div>

        <?php if (!$summary->can_withdraw_today): ?>
        <div class="alert alert-warning">
            <i class="material-icons">schedule</i>
            <div>
                <strong>محدودیت برداشت:</strong>
                به سقف برداشت روزانه رسیده‌اید.
                <?php if ($summary->last_withdrawal_at): ?>
                <br><small>آخرین برداشت: <?= to_jalali($summary->last_withdrawal_at) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

<script>
async function loadWithdrawalLimits(currency) {
    try {
        const r = await fetch(`<?= url('/withdrawal/limits') ?>?currency=${currency}`);
        const { limits } = await r.json();
        if (!limits) return;

        const box = document.getElementById('withdrawalLimitsBox');
        document.getElementById('limitsProfileLabel').textContent = `سطح شما: ${limits.profile_label}`;
        document.getElementById('limitsDetail').innerHTML = `
            <div class="col-6 col-md-3"><div class="bg-light rounded p-2 text-center">
                <div class="fw-bold">${limits.used_today}/${limits.daily_count === 999 ? '∞' : limits.daily_count}</div>
                <small class="text-muted">برداشت امروز</small>
            </div></div>
            <div class="col-6 col-md-3"><div class="bg-light rounded p-2 text-center">
                <div class="fw-bold">${limits.used_week}/${limits.weekly_count === 9999 ? '∞' : limits.weekly_count}</div>
                <small class="text-muted">این هفته</small>
            </div></div>
            <div class="col-6 col-md-3"><div class="bg-light rounded p-2 text-center">
                <div class="fw-bold">${Number(limits.min_amount).toLocaleString('fa')}</div>
                <small class="text-muted">حداقل برداشت</small>
            </div></div>
            <div class="col-6 col-md-3"><div class="bg-light rounded p-2 text-center">
                <div class="fw-bold">${Number(limits.max_amount).toLocaleString('fa')}</div>
                <small class="text-muted">حداکثر برداشت</small>
            </div></div>
        `;
        box.classList.remove('d-none');
    } catch(e) {}
}
document.addEventListener('DOMContentLoaded', () => loadWithdrawalLimits('IRT'));
document.querySelector('[name="currency"]')?.addEventListener('change', e => loadWithdrawalLimits(e.target.value));
</script>
    </div>

    <!-- هشدارها -->
    <div class="alert alert-danger">
        <i class="material-icons">warning</i>
        <div>
            <strong>توجه مهم:</strong>
            <ul style="margin: 10px 0 0 20px; padding: 0;">
                <li>روزانه فقط یکبار امکان برداشت وجود دارد</li>
                <li>حداقل مبلغ برداشت: <?= $siteCurrency === 'irt' ? e(number_format($minWithdrawal)) . ' تومان' : e(to_jalali($minWithdrawal, '', true)) . ' USDT' ?></li>
                <li>پس از ثبت درخواست، موجودی قفل می‌شود تا زمان تأیید یا رد</li>
                <li><?php if ($siteCurrency === 'irt'): ?>
                    برداشت فقط به کارت‌های تأییدشده امکان‌پذیر است
                    <?php else: ?>
                    برداشت به آدرس کیف پول شخصی شما انجام می‌شود
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>

    <!-- فرم برداشت -->
    <div class="form-card">
        <h3>اطلاعات برداشت</h3>

        <form method="POST" action="<?= url('/wallet/withdraw') ?>" id="withdrawalForm">
            <?= csrf_field() ?>
            
            <!-- ✅ Hidden Security Fields -->
            <input type="hidden" name="idempotency_key" id="idempotencyKey" value="">
            <input type="hidden" name="device_fingerprint" id="deviceFingerprint" value="">
            <input type="hidden" name="request_timestamp" id="requestTimestamp" value="">

            <?php if ($siteCurrency === 'irt'): ?>
            <!-- برداشت تومانی -->
            <div class="form-row">
                <div class="form-group full-width">
                    <label for="card_id">کارت مقصد: <span class="required">*</span></label>
                    <select id="card_id" name="card_id" class="form-control" required>
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($cards as $card): ?>
                        <option value="<?= e($card->id) ?>" 
                                data-bank="<?= e($card->bank_name) ?>"
                                <?= ($old['card_id'] ?? '') == $card->id ? 'selected' : '' ?>>
                            <?= e(substr($card->card_number, 0, 4)) ?>-****-****-<?= e(substr($card->card_number, -4)) ?> 
                            (<?= e($card->bank_name) ?>)
                            <?= $card->is_default ? '⭐ پیش‌فرض' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text">برداشت به این کارت واریز می‌شود</small>
                </div>
            </div>

            <div class="selected-card-info" id="card_info" style="display: none;">
                <i class="material-icons">credit_card</i>
                <div>
                    <strong>کارت انتخابی:</strong>
                    <p id="card_details"></p>
                </div>
            </div>

            <?php else: ?>
            <!-- برداشت تتری -->
            <div class="form-row">
                <div class="form-group full-width">
                    <label for="network">شبکه انتقال: <span class="required">*</span></label>
                    <select id="network" name="network" class="form-control" required>
                        <option value="">انتخاب کنید</option>
                        <option value="bnb20" <?= ($old['network'] ?? '') === 'bnb20' ? 'selected' : '' ?>>
                            BNB Smart Chain (BEP20) - سریع و ارزان
                        </option>
                        <option value="trc20" <?= ($old['network'] ?? '') === 'trc20' ? 'selected' : '' ?>>
                            TRON Network (TRC20) - بدون کارمزد
                        </option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="wallet_address">آدرس کیف پول USDT: <span class="required">*</span></label>
                    <input type="text" 
                           id="wallet_address" 
                           name="wallet_address" 
                           class="form-control ltr" 
                           placeholder="0x... یا T..."
                           value="<?= e($old['wallet_address'] ?? '') ?>"
                           required>
                    <small class="form-text">آدرس کیف پول شخصی خود را وارد کنید (حتماً شبکه صحیح را انتخاب کنید)</small>
                </div>
            </div>

            <div class="alert alert-danger">
                <i class="material-icons">warning</i>
                <div>
                    <strong>هشدار:</strong>
                    اشتباه در آدرس یا شبکه منجر به از دست رفتن دارایی می‌شود. لطفاً با دقت وارد کنید.
                </div>
            </div>
            <?php endif; ?>

            <!-- مبلغ -->
            <div class="form-row">
                <div class="form-group">
                    <label for="amount">
                        مبلغ برداشت (<?= $siteCurrency === 'irt' ? 'تومان' : 'USDT' ?>): 
                        <span class="required">*</span>
                    </label>
                    <input type="number" 
                           id="amount" 
                           name="amount" 
                           class="form-control" 
                           placeholder="مثال: <?= $siteCurrency === 'irt' ? '100000' : '50' ?>"
                           min="<?= e($minWithdrawal) ?>"
                           max="<?= $siteCurrency === 'irt' ? $summary->balance_irt : $summary->balance_usdt ?>"
                           step="<?= $siteCurrency === 'irt' ? '1000' : '0.01' ?>"
                           value="<?= e($old['amount'] ?? '') ?>"
                           required>
                    <small class="form-text">
                        حداقل: <?= $siteCurrency === 'irt' ? number_format($minWithdrawal) : to_jalali($minWithdrawal, '', true) ?> 
                        - حداکثر: <?= $siteCurrency === 'irt' ? number_format($summary->balance_irt) : number_format($summary->balance_usdt, 4) ?>
                    </small>
                </div>

                <div class="form-group">
                    <label>مبلغ قابل دریافت:</label>
                    <div class="receive-amount-display">
                        <span id="receive_amount">0</span>
                        <small><?= $siteCurrency === 'irt' ? 'تومان' : 'USDT' ?></small>
                    </div>
                    <small class="form-text">پس از کسر کارمزد (در صورت وجود)</small>
                </div>
            </div>

            <!-- مبالغ سریع -->
            <div class="quick-amounts">
                <span class="quick-label">انتخاب سریع:</span>
                <?php if ($siteCurrency === 'irt'): ?>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(<?= e(min(100000, $summary->balance_irt)) ?>)">
                        100,000
                    </button>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(<?= e(min(250000, $summary->balance_irt)) ?>)">
                        250,000
                    </button>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(<?= e(min(500000, $summary->balance_irt)) ?>)">
                        500,000
                    </button>
                <?php else: ?>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(<?= e(min(50, $summary->balance_usdt)) ?>)">
                        50
                    </button>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(<?= e(min(100, $summary->balance_usdt)) ?>)">
                        100
                    </button>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(<?= e(min(500, $summary->balance_usdt)) ?>)">
                        500
                    </button>
                <?php endif; ?>
                <button type="button" class="quick-amount-btn all" onclick="setMaxAmount()">
                    <i class="material-icons">select_all</i>
                    همه موجودی
                </button>
            </div>

            <!-- تأیید و ارسال -->
            <div class="confirmation-box">
                <label class="checkbox-container">
                    <input type="checkbox" id="confirm_withdrawal" required>
                    <span class="checkmark"></span>
                    <span class="checkbox-label">
                        اطلاعات وارد شده را بررسی کردم و از صحت آن اطمینان دارم.
                        می‌دانم که پس از ثبت درخواست، موجودی قفل می‌شود.
                    </span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-danger btn-lg" id="submit_btn" disabled>
                    <i class="material-icons">send</i>
                    ثبت درخواست برداشت
                </button>
                <a href="<?= url('/wallet') ?>" class="btn btn-outline btn-lg">
                    انصراف
                </a>
            </div>
        </form>
    </div>

    <?php else: ?>
    <div class="alert alert-danger">
        <i class="material-icons">error</i>
        خطا در دریافت اطلاعات کیف پول
    </div>
    <?php endif; ?>
</div>

<script>
const maxBalance = <?= $siteCurrency === 'irt' ? $summary->balance_irt : $summary->balance_usdt ?>;
const minAmount = <?= e($minWithdrawal) ?>;
const withdrawalFee = <?= (float)config('withdrawal_fee_' . $siteCurrency, 0) ?>;

// نمایش اطلاعات کارت انتخابی
document.getElementById('card_id')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const cardInfo = document.getElementById('card_info');
    const cardDetails = document.getElementById('card_details');
    
    if (this.value) {
        const bankName = selectedOption.dataset.bank;
        const cardNumber = selectedOption.text.split('(')[0].trim();
        
        cardDetails.innerHTML = `${cardNumber}<br>بانک ${bankName}`;
        cardInfo.style.display = 'flex';
    } else {
        cardInfo.style.display = 'none';
    }
});

// محاسبه مبلغ دریافتی
document.getElementById('amount').addEventListener('input', function() {
    const amount = parseFloat(this.value) || 0;
    const fee = (amount * withdrawalFee) / 100;
    const receiveAmount = amount - fee;
    
    document.getElementById('receive_amount').textContent = 
        <?= $siteCurrency === 'irt' ? 'receiveAmount.toLocaleString()' : 'receiveAmount.toFixed(4)' ?>;
});

function setAmount(amount) {
    document.getElementById('amount').value = amount;
    document.getElementById('amount').dispatchEvent(new Event('input'));
}

function setMaxAmount() {
    setAmount(maxBalance);
}

// فعال کردن دکمه ارسال
document.getElementById('confirm_withdrawal').addEventListener('change', function() {
    document.getElementById('submit_btn').disabled = !this.checked;
});

// اعتبارسنجی فرم
document.getElementById('withdrawalForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('amount').value);
    
    if (amount < minAmount) {
        e.preventDefault();
        notyf.error('مبلغ برداشت کمتر از حداقل مجاز است');
        return false;
    }
    
    if (amount > maxBalance) {
        e.preventDefault();
        notyf.error('موجودی کافی نیست');
        return false;
    }
    
    <?php if ($siteCurrency === 'usdt'): ?>
    const walletAddress = document.getElementById('wallet_address').value;
    const network = document.getElementById('network').value;
    
    if (network === 'bnb20' && !walletAddress.startsWith('0x')) {
        e.preventDefault();
        notyf.error('آدرس BNB20 باید با 0x شروع شود');
        return false;
    }
    
    if (network === 'trc20' && !walletAddress.startsWith('T')) {
        e.preventDefault();
        notyf.error('آدرس TRC20 باید با T شروع شود');
        return false;
    }
    <?php endif; ?>
    
    // ✅ Generate Idempotency Key on page load (once)
    if (!document.getElementById('idempotencyKey').value) {
        document.getElementById('idempotencyKey').value = generateIdempotencyKey();
    }
    
    // ✅ Generate Device Fingerprint
    document.getElementById('deviceFingerprint').value = generateDeviceFingerprint();
    
    // ✅ Set timestamp when form is submitted
    form.addEventListener('submit', function() {
        document.getElementById('requestTimestamp').value = Date.now();
    });
    
    if (!confirm('آیا از ثبت درخواست برداشت اطمینان دارید؟')) {
        e.preventDefault();
        return false;
    }
});

/**
 * تولید Idempotency Key یکتا
 * فرمت: WTH_YYYYMMDD_HHMMSS_RANDOM
 */
function generateIdempotencyKey() {
    const now = new Date();
    const timestamp = now.getFullYear() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0') + '_' +
        String(now.getHours()).padStart(2, '0') +
        String(now.getMinutes()).padStart(2, '0') +
        String(now.getSeconds()).padStart(2, '0');
    
    const random = Math.random().toString(36).substring(2, 15);
    
    return `WTH_${timestamp}_${random}`;
}

/**
 * تولید Device Fingerprint ساده
 * برای شناسایی دستگاه کاربر
 */
function generateDeviceFingerprint() {
    const components = [
        navigator.userAgent,
        navigator.language || navigator.userLanguage,
        screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
        new Date().getTimezoneOffset(),
        navigator.hardwareConcurrency || 'unknown',
        navigator.deviceMemory || 'unknown'
    ];
    
    // Simple hash function (for client-side)
    const str = components.join('|');
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        const char = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash; // Convert to 32bit integer
    }
    
    // Convert to hex and return first 16 characters
    return Math.abs(hash).toString(16).padStart(16, '0').substring(0, 16);
}

// ✅ Log for debugging (remove in production)
console.log('🔐 Security initialized:', {
    idempotency_key: document.getElementById('idempotencyKey').value,
    device_fingerprint: document.getElementById('deviceFingerprint').value
});
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>