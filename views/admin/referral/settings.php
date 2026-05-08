<?php
$title = 'تنظیمات سیستم معرفی';
$layout = 'admin';
$session = \Core\Session::getInstance();
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons text-primary">settings</i>
                تنظیمات سیستم معرفی و کمیسیون
            </h4>
        </div>
        <a href="<?= url('/admin/referral') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت
        </a>
    </div>
</div>

<?php if ($flash = $session->getFlash('success')): ?>
<div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
    <i class="material-icons" style="font-size:18px;vertical-align:middle;">check_circle</i>
    <?= e($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($flash = $session->getFlash('error')): ?>
<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
    <i class="material-icons" style="font-size:18px;vertical-align:middle;">error</i>
    <?= e($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form action="<?= url('/admin/referral/settings/save') ?>" method="POST">
    <?= csrf_field() ?>

    <!-- وضعیت کلی -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">وضعیت کلی</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="referral_commission_enabled" value="0">
                        <input type="checkbox" name="referral_commission_enabled" value="1" class="form-check-input"
                               id="enabled" <?= setting('referral_commission_enabled', 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enabled">سیستم کمیسیون فعال</label>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="referral_commission_auto_pay" value="0">
                        <input type="checkbox" name="referral_commission_auto_pay" value="1" class="form-check-input"
                               id="auto_pay" <?= setting('referral_commission_auto_pay', 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="auto_pay">پرداخت خودکار کمیسیون</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- درصد کمیسیون -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">درصد کمیسیون از هر منبع</h6></div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($sourceTypes as $key => $label): ?>
                <div class="col-md-3 mb-3">
                    <label class="form-label"><?= e($label) ?></label>
                    <div class="input-group">
                        <input type="number" name="referral_commission_<?= e($key) ?>_percent" 
                               class="form-control" step="0.5" min="0" max="100"
                               value="<?= e(setting("referral_commission_{$key}_percent", 0)) ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- حداقل پرداخت -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">حداقل مبلغ پرداخت</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">حداقل پرداخت (تومان)</label>
                    <input type="number" name="referral_commission_min_payout" class="form-control"
                           value="<?= e(setting('referral_commission_min_payout', 10000)) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">حداقل پرداخت (USDT)</label>
                    <input type="number" name="referral_commission_min_payout_usdt" class="form-control" step="0.01"
                           value="<?= e(setting('referral_commission_min_payout_usdt', 1)) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- پاداش ثبت‌نام -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">پاداش ثبت‌نام زیرمجموعه</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">پاداش ثبت‌نام (تومان)</label>
                    <input type="number" name="referral_signup_bonus" class="form-control"
                           value="<?= e(setting('referral_signup_bonus', 0)) ?>">
                    <small class="text-muted">0 = غیرفعال</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">پاداش ثبت‌نام (USDT)</label>
                    <input type="number" name="referral_signup_bonus_usdt" class="form-control" step="0.01"
                           value="<?= e(setting('referral_signup_bonus_usdt', 0)) ?>">
                    <small class="text-muted">0 = غیرفعال</small>
                </div>
            </div>
        </div>
    </div>

    <!-- ضدتقلب -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">
            <i class="material-icons text-danger" style="font-size:18px;vertical-align:middle;">shield</i>
            تنظیمات ضدتقلب (Anti-Farming)
        </h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">حداکثر ثبت‌نام روزانه</label>
                    <input type="number" name="referral_max_daily_signups" class="form-control" min="1"
                           value="<?= e(setting('referral_max_daily_signups', 5)) ?>">
                    <small class="text-muted">تعداد مجاز ثبت‌نام زیرمجموعه در روز</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">آستانه تشخیص Farming</label>
                    <input type="number" name="referral_farming_threshold" class="form-control" min="1"
                           value="<?= e(setting('referral_farming_threshold', 10)) ?>">
                    <small class="text-muted">اگر بیشتر از این تعداد ثبت‌نام در روز → اقدام</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">اقدام در صورت Farming</label>
                    <select name="referral_farming_action" class="form-select">
                        <option value="warn" <?= setting('referral_farming_action', 'warn') === 'warn' ? 'selected' : '' ?>>هشدار + افزایش امتیاز تقلب</option>
                        <option value="block" <?= setting('referral_farming_action') === 'block' ? 'selected' : '' ?>>مسدودسازی ثبت‌نام زیرمجموعه</option>
                        <option value="ban" <?= setting('referral_farming_action') === 'ban' ? 'selected' : '' ?>>بن کاربر</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3 mb-4">
        <a href="<?= url('/admin/referral') ?>" class="btn btn-outline-secondary">انصراف</a>
        <button type="submit" class="btn btn-primary">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">save</i>
            ذخیره تنظیمات
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>