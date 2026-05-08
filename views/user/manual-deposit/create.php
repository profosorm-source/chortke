<?php
$pageTitle = $pageTitle ?? 'واریز دستی';
$cards = $cards ?? [];
$siteCardNumber = $siteCardNumber ?? '';
$siteSheba = $siteSheba ?? '';
$siteBankName = $siteBankName ?? '';
$old = old();
?>
<?php
$title = $title ?? 'واریز کارت به کارت';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-manual-deposit.css') ?>">


    <!-- Header -->
    <div class="content-header">
        <h1>واریز دستی</h1>
        <a href="<?= url('/wallet/deposit') ?>" class="btn btn-outline">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <!-- مراحل واریز -->
    <div class="steps-container">
        <div class="step active">
            <div class="step-number">1</div>
            <div class="step-title">واریز به حساب سایت</div>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <div class="step-number">2</div>
            <div class="step-title">ثبت اطلاعات واریز</div>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <div class="step-number">3</div>
            <div class="step-title">بررسی و تأیید</div>
        </div>
    </div>

    <!-- اطلاعات حساب سایت -->
    <div class="account-info-card">
        <h3>
            <i class="material-icons">account_balance</i>
            اطلاعات حساب سایت برای واریز
        </h3>
        
        <div class="info-grid">
            <div class="info-item">
                <span class="label">شماره کارت:</span>
                <div class="value-with-copy">
                    <span class="value card-number"><?= e($siteCardNumber) ?></span>
                    <button class="copy-btn" onclick="copyToClipboard('<?= e($siteCardNumber) ?>')">
                        <i class="material-icons">content_copy</i>
                    </button>
                </div>
            </div>

            <?php if ($siteSheba): ?>
            <div class="info-item">
                <span class="label">شماره شبا:</span>
                <div class="value-with-copy">
                    <span class="value sheba-number" dir="ltr">IR<?= e($siteSheba) ?></span>
                    <button class="copy-btn" onclick="copyToClipboard('IR<?= e($siteSheba) ?>')">
                        <i class="material-icons">content_copy</i>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div class="info-item">
                <span class="label">نام بانک:</span>
                <span class="value"><?= e($siteBankName) ?></span>
            </div>

            <div class="info-item">
                <span class="label">به نام:</span>
                <span class="value">سایت چرتکه</span>
            </div>
        </div>

        <div class="alert alert-warning">
            <i class="material-icons">warning</i>
            <div>
                <strong>توجه:</strong>
                حتماً از یکی از کارت‌های تأییدشده خود واریز کنید. واریز از کارت دیگران رد خواهد شد.
            </div>
        </div>
    </div>

    <!-- فرم ثبت واریز -->
    <div class="form-card">
        <h3>ثبت اطلاعات واریز</h3>
        
        <form method="POST" action="<?= url('/wallet/deposit/manual') ?>" enctype="multipart/form-data" id="depositForm">
            <?= csrf_field() ?>
            
            <!-- ✅ Hidden Security Fields -->
            <input type="hidden" name="idempotency_key" id="idempotencyKey" value="">
            <input type="hidden" name="device_fingerprint" id="deviceFingerprint" value="">
            <input type="hidden" name="request_timestamp" id="requestTimestamp" value="">

            <div class="form-row">
                <div class="form-group">
                    <label for="card_id">کارت بانکی شما: <span class="required">*</span></label>
                    <select id="card_id" name="card_id" class="form-control" required>
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($cards as $card): ?>
                        <option value="<?= e($card->id) ?>" <?= ($old['card_id'] ?? '') == $card->id ? 'selected' : '' ?>>
                            <?= e(substr($card->card_number, 0, 4)) ?>-****-****-<?= e(substr($card->card_number, -4)) ?> 
                            (<?= e($card->bank_name) ?>)
                            <?= $card->is_default ? '⭐' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text">کارتی که از آن واریز کرده‌اید را انتخاب کنید</small>
                </div>

                <div class="form-group">
                    <label for="amount">مبلغ واریزی (تومان): <span class="required">*</span></label>
                    <input type="number" 
                           id="amount" 
                           name="amount" 
                           class="form-control" 
                           placeholder="مثال: 100000"
                           min="10000"
                           step="1000"
                           value="<?= e($old['amount'] ?? '') ?>"
                           required>
                    <small class="form-text">حداقل مبلغ: 10,000 تومان</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="tracking_code">شماره پیگیری: <span class="required">*</span></label>
                    <input type="text" 
                           id="tracking_code" 
                           name="tracking_code" 
                           class="form-control ltr" 
                           placeholder="مثال: 123456789"
                           value="<?= e($old['tracking_code'] ?? '') ?>"
                           required>
                    <small class="form-text">شماره پیگیری تراکنش بانکی</small>
                </div>

                <div class="form-group">
                    <label for="deposit_date">تاریخ واریز: <span class="required">*</span></label>
                    <input type="date" 
                           id="deposit_date" 
                           name="deposit_date" 
                           class="form-control" 
                           max="<?= date('Y-m-d') ?>"
                           value="<?= e($old['deposit_date'] ?? date('Y-m-d')) ?>"
                           required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="deposit_time">ساعت واریز: <span class="required">*</span></label>
                    <input type="time" 
                           id="deposit_time" 
                           name="deposit_time" 
                           class="form-control" 
                           value="<?= e($old['deposit_time'] ?? date('H:i')) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label for="receipt_image">تصویر فیش واریز:</label>
                    <input type="file" 
                           id="receipt_image" 
                           name="receipt_image" 
                           class="form-control" 
                           accept="image/*">
                    <small class="form-text">اختیاری - حداکثر 2MB - فرمت: JPG, PNG</small>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="material-icons">check</i>
                    ثبت درخواست واریز
                </button>
                <a href="<?= url('/wallet/deposit') ?>" class="btn btn-outline btn-lg">
                    انصراف
                </a>
            </div>
        </form>
    </div>

    <!-- راهنما -->
    <div class="help-card">
        <h4>
            <i class="material-icons">help</i>
            راهنمای واریز دستی
        </h4>
        <ol>
            <li>ابتدا از یکی از کارت‌های تأییدشده خود به حساب سایت واریز کنید</li>
            <li>اطلاعات دقیق واریز (شماره پیگیری، تاریخ و ساعت) را در فرم بالا وارد کنید</li>
            <li>در صورت امکان، تصویر فیش واریز را آپلود کنید (سرعت تأیید بیشتر می‌شود)</li>
            <li>درخواست شما حداکثر ظرف 2 تا 24 ساعت بررسی و تأیید می‌شود</li>
            <li>پس از تأیید، مبلغ به کیف پول شما افزوده می‌شود</li>
        </ol>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        notyf.success('کپی شد!');
    }).catch(err => {
        notyf.error('خطا در کپی کردن');
    });
}

// اعتبارسنجی فایل
document.getElementById('receipt_image')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        // بررسی حجم (2MB)
        if (file.size > 2 * 1024 * 1024) {
            notyf.error('حجم فایل نباید بیشتر از 2 مگابایت باشد');
            e.target.value = '';
            return;
        }
        
        // بررسی فرمت
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!allowedTypes.includes(file.type)) {
            notyf.error('فقط فرمت JPG و PNG مجاز است');
            e.target.value = '';
            return;
        }
    }
});

// ✅ Initialize Security Fields
(function() {
    // Generate Idempotency Key (once per page load)
    if (!document.getElementById('idempotencyKey').value) {
        document.getElementById('idempotencyKey').value = generateIdempotencyKey();
    }
    
    // Generate Device Fingerprint
    document.getElementById('deviceFingerprint').value = generateDeviceFingerprint();
    
    // Set timestamp on form submit
    const form = document.getElementById('depositForm');
    form?.addEventListener('submit', function() {
        document.getElementById('requestTimestamp').value = Date.now();
    });
    
    console.log('🔐 Security initialized for manual deposit');
})();

/**
 * Generate unique Idempotency Key
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
    
    return `MDP_${timestamp}_${random}`;
}

/**
 * Generate Device Fingerprint
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
    
    const str = components.join('|');
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        const char = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash;
    }
    
    return Math.abs(hash).toString(16).padStart(16, '0').substring(0, 16);
}
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>