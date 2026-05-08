<?php
$title = 'تنظیمات اعلان‌ها';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-notifications.css') ?>">

<div class="notifications-prefs-page">

    <div class="page-header mb-4">
        <div>
            <h4><i class="fas fa-cog"></i> تنظیمات اعلان‌ها</h4>
            <p class="text-muted mb-0">کنترل کنید چه نوتیفیکیشن‌هایی و از چه کانالی دریافت کنید</p>
        </div>
        <a href="<?= url('/notifications') ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>

    <form id="prefsForm">
        <?= csrf_field() ?>

        <div class="row g-4">

            <!-- In-App -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="fas fa-bell text-primary"></i>
                        <h6 class="mb-0">اعلان‌های داخل سایت</h6>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input master-toggle" type="checkbox"
                                   id="in_app_enabled" name="in_app_enabled"
                                   data-group="in_app"
                                   <?= !empty($preferences->in_app_enabled) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="in_app_enabled">فعال</label>
                        </div>
                    </div>
                    <div class="card-body pref-group" data-group="in_app">
                        <?php
                        $inAppFields = [
                            'in_app_deposit'    => ['label' => 'واریز و پرداخت',       'icon' => 'fa-arrow-down text-success'],
                            'in_app_withdrawal' => ['label' => 'برداشت',               'icon' => 'fa-arrow-up text-danger'],
                            'in_app_task'       => ['label' => 'تسک‌ها',               'icon' => 'fa-tasks text-info'],
                            'in_app_investment' => ['label' => 'سرمایه‌گذاری',         'icon' => 'fa-chart-line text-warning'],
                            'in_app_lottery'    => ['label' => 'قرعه‌کشی',             'icon' => 'fa-gift text-purple'],
                            'in_app_referral'   => ['label' => 'معرفی و کمیسیون',      'icon' => 'fa-users text-teal'],
                            'in_app_security'   => ['label' => 'هشدارهای امنیتی',      'icon' => 'fa-shield-alt text-danger'],
                            'in_app_kyc'        => ['label' => 'احراز هویت',           'icon' => 'fa-id-card text-primary'],
                            'in_app_system'     => ['label' => 'اطلاعیه‌های سیستمی',   'icon' => 'fa-info-circle text-secondary'],
                        ];
                        ?>
                        <?php foreach ($inAppFields as $key => $info): ?>
                        <div class="pref-item d-flex align-items-center justify-content-between py-2 border-bottom">
                            <label class="d-flex align-items-center gap-2 mb-0" for="<?= e($key) ?>">
                                <i class="fas <?= e($info['icon']) ?>"></i>
                                <?= e($info['label']) ?>
                            </label>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input pref-child" type="checkbox"
                                       id="<?= e($key) ?>" name="<?= e($key) ?>"
                                       <?= !empty($preferences->$key) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Email -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="fas fa-envelope text-warning"></i>
                        <h6 class="mb-0">ایمیل</h6>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input master-toggle" type="checkbox"
                                   id="email_enabled" name="email_enabled"
                                   data-group="email"
                                   <?= !empty($preferences->email_enabled) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="email_enabled">فعال</label>
                        </div>
                    </div>
                    <div class="card-body pref-group" data-group="email">
                        <?php
                        $emailFields = [
                            'email_deposit'    => ['label' => 'واریز و پرداخت',     'icon' => 'fa-arrow-down text-success'],
                            'email_withdrawal' => ['label' => 'برداشت',             'icon' => 'fa-arrow-up text-danger'],
                            'email_kyc'        => ['label' => 'احراز هویت',         'icon' => 'fa-id-card text-primary'],
                            'email_investment' => ['label' => 'سرمایه‌گذاری',       'icon' => 'fa-chart-line text-warning'],
                            'email_lottery'    => ['label' => 'قرعه‌کشی',           'icon' => 'fa-gift text-purple'],
                            'email_security'   => ['label' => 'هشدارهای امنیتی',    'icon' => 'fa-shield-alt text-danger'],
                            'email_system'     => ['label' => 'اطلاعیه‌های سیستمی', 'icon' => 'fa-info-circle text-secondary'],
                            'email_marketing'  => ['label' => 'تبلیغاتی/بازاریابی','icon' => 'fa-bullhorn text-muted'],
                        ];
                        ?>
                        <?php foreach ($emailFields as $key => $info): ?>
                        <div class="pref-item d-flex align-items-center justify-content-between py-2 border-bottom">
                            <label class="d-flex align-items-center gap-2 mb-0" for="<?= e($key) ?>">
                                <i class="fas <?= e($info['icon']) ?>"></i>
                                <?= e($info['label']) ?>
                            </label>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input pref-child" type="checkbox"
                                       id="<?= e($key) ?>" name="<?= e($key) ?>"
                                       <?= !empty($preferences->$key) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Push -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="fas fa-mobile-alt text-success"></i>
                        <h6 class="mb-0">Push Notification (اپ موبایل)</h6>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input master-toggle" type="checkbox"
                                   id="push_enabled" name="push_enabled"
                                   data-group="push"
                                   <?= !empty($preferences->push_enabled) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="push_enabled">فعال</label>
                        </div>
                    </div>
                    <div class="card-body pref-group" data-group="push">
                        <?php
                        $pushFields = [
                            'push_deposit'    => ['label' => 'واریز',              'icon' => 'fa-arrow-down text-success'],
                            'push_withdrawal' => ['label' => 'برداشت',             'icon' => 'fa-arrow-up text-danger'],
                            'push_task'       => ['label' => 'تسک‌ها',             'icon' => 'fa-tasks text-info'],
                            'push_security'   => ['label' => 'هشدارهای امنیتی',   'icon' => 'fa-shield-alt text-danger'],
                            'push_lottery'    => ['label' => 'قرعه‌کشی',          'icon' => 'fa-gift text-purple'],
                            'push_system'     => ['label' => 'سیستمی',            'icon' => 'fa-info-circle text-secondary'],
                        ];
                        ?>
                        <?php foreach ($pushFields as $key => $info): ?>
                        <div class="pref-item d-flex align-items-center justify-content-between py-2 border-bottom">
                            <label class="d-flex align-items-center gap-2 mb-0" for="<?= e($key) ?>">
                                <i class="fas <?= e($info['icon']) ?>"></i>
                                <?= e($info['label']) ?>
                            </label>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input pref-child" type="checkbox"
                                       id="<?= e($key) ?>" name="<?= e($key) ?>"
                                       <?= !empty($preferences->$key) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- SMS + DND -->
            <div class="col-md-6 d-flex flex-column gap-4">

                <!-- SMS -->
                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="fas fa-sms text-info"></i>
                        <h6 class="mb-0">پیامک</h6>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input master-toggle" type="checkbox"
                                   id="sms_enabled" name="sms_enabled"
                                   data-group="sms"
                                   <?= !empty($preferences->sms_enabled) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sms_enabled">فعال</label>
                        </div>
                    </div>
                    <div class="card-body pref-group" data-group="sms">
                        <?php
                        $smsFields = [
                            'sms_security'   => ['label' => 'هشدارهای امنیتی', 'icon' => 'fa-shield-alt text-danger'],
                            'sms_withdrawal' => ['label' => 'برداشت',          'icon' => 'fa-arrow-up text-danger'],
                        ];
                        ?>
                        <?php foreach ($smsFields as $key => $info): ?>
                        <div class="pref-item d-flex align-items-center justify-content-between py-2 border-bottom">
                            <label class="d-flex align-items-center gap-2 mb-0" for="<?= e($key) ?>">
                                <i class="fas <?= e($info['icon']) ?>"></i>
                                <?= e($info['label']) ?>
                            </label>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input pref-child" type="checkbox"
                                       id="<?= e($key) ?>" name="<?= e($key) ?>"
                                       <?= !empty($preferences->$key) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Do Not Disturb -->
                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="fas fa-moon text-primary"></i>
                        <h6 class="mb-0">مزاحم نشوید (DND)</h6>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input" type="checkbox"
                                   id="dnd_enabled" name="dnd_enabled"
                                   <?= !empty($preferences->dnd_enabled) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="dnd_enabled">فعال</label>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            در این بازه فقط نوتیفیکیشن‌های <strong>فوری</strong> ارسال می‌شوند.
                            بقیه تا پایان بازه به تأخیر می‌افتند.
                        </p>
                        <div class="row g-2 align-items-center">
                            <div class="col-5">
                                <label class="form-label small">از ساعت</label>
                                <input type="time" class="form-control" id="dnd_start" name="dnd_start"
                                       value="<?= e(substr($preferences->dnd_start ?? '23:00:00', 0, 5)) ?>">
                            </div>
                            <div class="col-2 text-center pt-4">
                                <i class="fas fa-arrow-left text-muted"></i>
                            </div>
                            <div class="col-5">
                                <label class="form-label small">تا ساعت</label>
                                <input type="time" class="form-control" id="dnd_end" name="dnd_end"
                                       value="<?= e(substr($preferences->dnd_end ?? '07:00:00', 0, 5)) ?>">
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> ذخیره تنظیمات
            </button>
            <a href="<?= url('/notifications') ?>" class="btn btn-outline-secondary">
                انصراف
            </a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const notyf = new Notyf({ duration: 2500, position: { x: 'right', y: 'top' } });

    // master toggle — غیرفعال/فعال کردن گروه
    document.querySelectorAll('.master-toggle').forEach(master => {
        const groupName = master.dataset.group;

        const updateGroup = () => {
            const group = document.querySelector(`.pref-group[data-group="${groupName}"]`);
            if (!group) return;
            group.querySelectorAll('.pref-child').forEach(child => {
                child.disabled = !master.checked;
            });
        };

        master.addEventListener('change', updateGroup);
        updateGroup(); // اعمال اولیه
    });

    // ذخیره
    document.getElementById('prefsForm').addEventListener('submit', async function (e) {
        e.preventDefault();

        const formData = new FormData(this);
        const payload  = {};

        // checkbox‌های تیک‌نخورده در FormData نیستند → باید صفر بشوند
        const allCheckboxes = this.querySelectorAll('input[type="checkbox"]');
        allCheckboxes.forEach(cb => {
            if (cb.name) payload[cb.name] = 0;
        });

        formData.forEach((v, k) => {
            payload[k] = (v === 'on') ? 1 : v;
        });

        // time fields
        const dndStart = document.getElementById('dnd_start')?.value;
        const dndEnd   = document.getElementById('dnd_end')?.value;
        if (dndStart) payload['dnd_start'] = dndStart + ':00';
        if (dndEnd)   payload['dnd_end']   = dndEnd   + ':00';

        try {
            const res  = await fetch('<?= url('/notifications/preferences/update') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': payload.csrf_token || '<?= csrf_token() ?>'
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            data.success ? notyf.success(data.message) : notyf.error(data.message || 'خطا در ذخیره');

        } catch {
            notyf.error('خطا در ارتباط با سرور');
        }
    });
});
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
