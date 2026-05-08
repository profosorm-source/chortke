<?php
$pageTitle = 'تنظیمات CAPTCHA';
ob_start();

$settings = [
    'captcha_enabled' => setting('captcha_enabled'),
    'captcha_type' => setting('captcha_type'),
    'recaptcha_site_key' => setting('recaptcha_site_key'),
    'recaptcha_secret_key' => setting('recaptcha_secret_key'),
    'recaptcha_v3_threshold' => setting('recaptcha_v3_threshold'),
    'captcha_expire_minutes' => setting('captcha_expire_minutes'),
    'captcha_max_attempts' => setting('captcha_max_attempts')
];
?>

<div class="container-fluid">
    <h4 class="mb-4">تنظیمات CAPTCHA</h4>

    <div class="card">
        <div class="card-body">
            <form id="captchaSettingsForm">
                <!-- فعال/غیرفعال -->
                <div class="form-group row">
                    <label class="col-md-3 col-form-label">
                        <strong>فعال/غیرفعال CAPTCHA</strong>
                    </label>
                    <div class="col-md-6">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="captcha_enabled" 
                                   <?= $settings['captcha_enabled'] ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="captcha_enabled">فعال</label>
                        </div>
                    </div>
                </div>

                <!-- نوع پیش‌فرض -->
                <div class="form-group row">
                    <label class="col-md-3 col-form-label">
                        <strong>نوع پیش‌فرض</strong>
                    </label>
                    <div class="col-md-6">
                        <select class="form-control" id="captcha_type">
                            <option value="math" <?= $settings['captcha_type'] === 'math' ? 'selected' : '' ?>>Math (ریاضی ساده)</option>
                            <option value="image" <?= $settings['captcha_type'] === 'image' ? 'selected' : '' ?>>Image (تصویری)</option>
                            <option value="behavioral" <?= $settings['captcha_type'] === 'behavioral' ? 'selected' : '' ?>>Behavioral (رفتاری)</option>
                            <option value="recaptcha_v2" <?= $settings['captcha_type'] === 'recaptcha_v2' ? 'selected' : '' ?>>reCAPTCHA v2</option>
                            <option value="recaptcha_v3" <?= $settings['captcha_type'] === 'recaptcha_v3' ? 'selected' : '' ?>>reCAPTCHA v3</option>
                        </select>
                    </div>
                </div>

                <!-- reCAPTCHA Keys -->
                <div class="form-group row">
                    <label class="col-md-3 col-form-label">
                        <strong>reCAPTCHA Site Key</strong>
                    </label>
                    <div class="col-md-6">
                        <input type="text" class="form-control" id="recaptcha_site_key" 
                               value="<?= e($settings['recaptcha_site_key']) ?>">
                        <small class="form-text text-muted">
                            <a href="https://www.google.com/recaptcha/admin" target="_blank">دریافت از Google reCAPTCHA</a>
                        </small>
                    </div>
                </div>

                <div class="form-group row">
                    <label class="col-md-3 col-form-label">
                        <strong>reCAPTCHA Secret Key</strong>
                    </label>
                    <div class="col-md-6">
                        <input type="text" class="form-control" id="recaptcha_secret_key" 
                               value="<?= e($settings['recaptcha_secret_key']) ?>">
                    </div>
                </div>

                <!-- reCAPTCHA v3 Threshold -->
                <div class="form-group row">
                    <label class="col-md-3 col-form-label">
                        <strong>حد آستانه reCAPTCHA v3</strong>
                    </label>
                    <div class="col-md-6">
                        <input type="number" step="0.1" min="0" max="1" class="form-control" 
                               id="recaptcha_v3_threshold" value="<?= e($settings['recaptcha_v3_threshold']) ?>">
                        <small class="form-text text-muted">بین 0.0 (ربات) تا 1.0 (انسان)</small>
                    </div>
                </div>

                <!-- مدت اعتبار -->
                <div class="form-group row">
                    <label class="col-md-3 col-form-label">
                        <strong>مدت اعتبار (دقیقه)</strong>
                    </label>
                    <div class="col-md-6">
                        <input type="number" class="form-control" id="captcha_expire_minutes" 
                               value="<?= e($settings['captcha_expire_minutes']) ?>">
                    </div>
                </div>

                <!-- حداکثر تلاش -->
                <div class="form-group row">
                    <label class="col-md-3 col-form-label">
                        <strong>حداکثر تلاش اشتباه</strong>
                    </label>
                    <div class="col-md-6">
                        <input type="number" class="form-control" id="captcha_max_attempts" 
                               value="<?= e($settings['captcha_max_attempts']) ?>">
                    </div>
                </div>

                <!-- دکمه ذخیره -->
                <div class="form-group row">
                    <div class="col-md-9 offset-md-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="material-icons">save</i>
                            ذخیره تنظیمات
                        </button>
                        <a href="<?= url('/test-captcha') ?>" target="_blank" class="btn btn-info">
                            <i class="material-icons">visibility</i>
                            تست CAPTCHA
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('captchaSettingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const settings = {
        captcha_enabled: document.getElementById('captcha_enabled').checked ? '1' : '0',
        captcha_type: document.getElementById('captcha_type').value,
        recaptcha_site_key: document.getElementById('recaptcha_site_key').value,
        recaptcha_secret_key: document.getElementById('recaptcha_secret_key').value,
        recaptcha_v3_threshold: document.getElementById('recaptcha_v3_threshold').value,
        captcha_expire_minutes: document.getElementById('captcha_expire_minutes').value,
        captcha_max_attempts: document.getElementById('captcha_max_attempts').value
    };
    
    // ذخیره هر تنظیم
    const promises = Object.entries(settings).map(([key, value]) => {
        return fetch('<?= url('/admin/settings/update') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            },
            body: JSON.stringify({ key: key, value: value })
        });
    });
    
    Promise.all(promises).then(() => {
        notyf.success('تنظیمات ذخیره شد');
    }).catch(() => {
        notyf.error('خطا در ذخیره');
    });
});
</script>

<?php
$content = ob_get_clean();
require_once view_path('layouts.admin');
?>