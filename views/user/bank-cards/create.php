<?php
$pageTitle = $pageTitle ?? 'افزودن کارت بانکی';
$layout = 'user';
$old = old();
?>

<div class="main-content">
    <!-- Header -->
    <div class="content-header">
        <h1>افزودن کارت بانکی</h1>
        <a href="<?= url('/bank-cards') ?>" class="btn btn-outline">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <!-- راهنما -->
    <div class="alert alert-warning">
        <i class="material-icons">warning</i>
        <div>
            <strong>توجه مهم:</strong>
            <ul style="margin: 10px 0 0 20px; padding: 0;">
                <li>کارت بانکی باید حتماً به نام خودتان باشد</li>
                <li>اطلاعات وارد شده باید با مدارک احراز هویت شما مطابقت داشته باشد</li>
                <li>کارت‌هایی که به نام دیگران هستند، رد خواهند شد</li>
                <li>پس از ثبت، کارت توسط مدیریت بررسی و تأیید می‌شود</li>
            </ul>
        </div>
    </div>

    <!-- فرم -->
    <div class="form-card">
        <form method="POST" action="<?= url('/bank-cards/store') ?>" id="bankCardForm">
            <?= csrf_field() ?>

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="card_number">شماره کارت: <span class="required">*</span></label>
                    <input type="text" 
                           id="card_number" 
                           name="card_number" 
                           class="form-control ltr" 
                           placeholder="1234-5678-9012-3456"
                           maxlength="19"
                           value="<?= e($old['card_number'] ?? '') ?>"
                           required>
                    <small class="form-text">شماره کارت 16 رقمی خود را وارد کنید</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="bank_name">نام بانک: <span class="required">*</span></label>
                    <select id="bank_name" name="bank_name" class="form-control" required>
                        <option value="">انتخاب کنید</option>
                        <option value="ملی" <?= ($old['bank_name'] ?? '') === 'ملی' ? 'selected' : '' ?>>بانک ملی</option>
                        <option value="ملت" <?= ($old['bank_name'] ?? '') === 'ملت' ? 'selected' : '' ?>>بانک ملت</option>
                        <option value="صادرات" <?= ($old['bank_name'] ?? '') === 'صادرات' ? 'selected' : '' ?>>بانک صادرات</option>
                        <option value="تجارت" <?= ($old['bank_name'] ?? '') === 'تجارت' ? 'selected' : '' ?>>بانک تجارت</option>
                        <option value="سپه" <?= ($old['bank_name'] ?? '') === 'سپه' ? 'selected' : '' ?>>بانک سپه</option>
                        <option value="رفاه" <?= ($old['bank_name'] ?? '') === 'رفاه' ? 'selected' : '' ?>>بانک رفاه</option>
                        <option value="پاسارگاد" <?= ($old['bank_name'] ?? '') === 'پاسارگاد' ? 'selected' : '' ?>>بانک پاسارگاد</option>
                        <option value="پارسیان" <?= ($old['bank_name'] ?? '') === 'پارسیان' ? 'selected' : '' ?>>بانک پارسیان</option>
                        <option value="کشاورزی" <?= ($old['bank_name'] ?? '') === 'کشاورزی' ? 'selected' : '' ?>>بانک کشاورزی</option>
                        <option value="مسکن" <?= ($old['bank_name'] ?? '') === 'مسکن' ? 'selected' : '' ?>>بانک مسکن</option>
                        <option value="پست بانک" <?= ($old['bank_name'] ?? '') === 'پست بانک' ? 'selected' : '' ?>>پست بانک</option>
                        <option value="سامان" <?= ($old['bank_name'] ?? '') === 'سامان' ? 'selected' : '' ?>>بانک سامان</option>
                        <option value="سینا" <?= ($old['bank_name'] ?? '') === 'سینا' ? 'selected' : '' ?>>بانک سینا</option>
                        <option value="شهر" <?= ($old['bank_name'] ?? '') === 'شهر' ? 'selected' : '' ?>>بانک شهر</option>
                        <option value="آینده" <?= ($old['bank_name'] ?? '') === 'آینده' ? 'selected' : '' ?>>بانک آینده</option>
                        <option value="اقتصاد نوین" <?= ($old['bank_name'] ?? '') === 'اقتصاد نوین' ? 'selected' : '' ?>>بانک اقتصاد نوین</option>
                        <option value="دی" <?= ($old['bank_name'] ?? '') === 'دی' ? 'selected' : '' ?>>بانک دی</option>
                        <option value="سایر" <?= ($old['bank_name'] ?? '') === 'سایر' ? 'selected' : '' ?>>سایر</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cardholder_name">نام صاحب کارت: <span class="required">*</span></label>
                    <input type="text" 
                           id="cardholder_name" 
                           name="cardholder_name" 
                           class="form-control" 
                           placeholder="نام و نام خانوادگی"
                           value="<?= e($old['cardholder_name'] ?? '') ?>"
                           required>
                    <small class="form-text">طبق کارت بانکی</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="account_number">شماره حساب:</label>
                    <input type="text" 
                           id="account_number" 
                           name="account_number" 
                           class="form-control ltr" 
                           placeholder="1234567890"
                           value="<?= e($old['account_number'] ?? '') ?>">
                    <small class="form-text">اختیاری</small>
                </div>

                <div class="form-group">
                    <label for="sheba">شماره شبا:</label>
                    <div class="input-with-prefix">
                        <span class="prefix">IR</span>
                        <input type="text" 
                               id="sheba" 
                               name="sheba" 
                               class="form-control ltr" 
                               placeholder="000000000000000000000000"
                               maxlength="24"
                               value="<?= e($old['sheba'] ?? '') ?>">
                    </div>
                    <small class="form-text">24 رقم بدون IR - اختیاری</small>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="material-icons">check</i>
                    ثبت کارت بانکی
                </button>
                <a href="<?= url('/bank-cards') ?>" class="btn btn-outline btn-lg">
                    انصراف
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// فرمت کردن خودکار شماره کارت
document.getElementById('card_number').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
    let formattedValue = value.match(/.{1,4}/g)?.join('-') || value;
    e.target.value = formattedValue;
});

// فقط اعداد برای شبا
document.getElementById('sheba').addEventListener('input', function(e) {
    e.target.value = e.target.value.replace(/[^0-9]/gi, '');
});

// اعتبارسنجی فرم
document.getElementById('bankCardForm').addEventListener('submit', function(e) {
    const cardNumber = document.getElementById('card_number').value.replace(/-/g, '');
    
    if (cardNumber.length !== 16) {
        e.preventDefault();
        notyf.error('شماره کارت باید 16 رقم باشد');
        return false;
    }
    
    const sheba = document.getElementById('sheba').value;
    if (sheba && sheba.length !== 24) {
        e.preventDefault();
        notyf.error('شماره شبا باید 24 رقم باشد');
        return false;
    }
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>