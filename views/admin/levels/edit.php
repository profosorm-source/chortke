<?php
$title = 'ویرایش سطح: ' . e($level->name);
$layout = 'admin';
$session = \Core\Session::getInstance();
$old = $session->getFlash('old') ?? [];
$old = \is_array($old) ? (object) $old : $old;
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons" style="color:<?= e($level->color) ?>;"><?= e($level->icon ?? 'workspace_premium') ?></i>
                ویرایش سطح: <?= e($level->name) ?>
            </h4>
        </div>
        <a href="<?= url('/admin/levels') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت
        </a>
    </div>
</div>

<?php if ($flash = $session->getFlash('error')): ?>
<div class="alert alert-danger mt-3"><?= e($flash) ?></div>
<?php endif; ?>

<form action="<?= url('/admin/levels/' . $level->id . '/update') ?>" method="POST">
    <?= csrf_field() ?>

    <!-- اطلاعات پایه -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">اطلاعات پایه</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">نام سطح <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= e($old->name ?? $level->name) ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">آیکون Material</label>
                    <input type="text" name="icon" class="form-control" dir="ltr" value="<?= e($old->icon ?? $level->icon) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">رنگ</label>
                    <input type="color" name="color" class="form-control form-control-color" value="<?= e($old->color ?? $level->color) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <div class="form-check form-switch mt-4">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" <?= ($old->is_active ?? $level->is_active) ? 'checked' : '' ?>>
                        <label class="form-check-label">فعال</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- شرایط ارتقا با فعالیت -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">شرایط ارتقا با فعالیت</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">حداقل روز فعالیت</label>
                    <input type="number" name="min_active_days" class="form-control" min="0" value="<?= e($old->min_active_days ?? $level->min_active_days) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">حداقل تسک تکمیل‌شده</label>
                    <input type="number" name="min_completed_tasks" class="form-control" min="0" value="<?= e($old->min_completed_tasks ?? $level->min_completed_tasks) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">حداقل درآمد (تومان)</label>
                    <input type="number" name="min_total_earning" class="form-control" min="0" value="<?= e($old->min_total_earning ?? $level->min_total_earning) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">حداقل درآمد (USDT)</label>
                    <input type="number" name="min_total_earning_usdt" class="form-control" min="0" step="0.01" value="<?= e($old->min_total_earning_usdt ?? $level->min_total_earning_usdt) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- قیمت خرید -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">قیمت خرید</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">قیمت (تومان)</label>
                    <input type="number" name="purchase_price_irt" class="form-control" min="0" value="<?= e($old->purchase_price_irt ?? $level->purchase_price_irt) ?>">
                    <small class="text-muted">0 = غیرقابل خرید</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">قیمت (USDT)</label>
                    <input type="number" name="purchase_price_usdt" class="form-control" min="0" step="0.01" value="<?= e($old->purchase_price_usdt ?? $level->purchase_price_usdt) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">مدت اعتبار (روز)</label>
                    <input type="number" name="purchase_duration_days" class="form-control" min="1" value="<?= e($old->purchase_duration_days ?? $level->purchase_duration_days) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- پاداش‌ها -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">پاداش‌ها و مزایا</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">افزایش درآمد تسک (%)</label>
                    <input type="number" name="earning_bonus_percent" class="form-control" min="0" step="0.5" value="<?= e($old->earning_bonus_percent ?? $level->earning_bonus_percent) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">افزایش کمیسیون معرفی (%)</label>
                    <input type="number" name="referral_bonus_percent" class="form-control" min="0" step="0.5" value="<?= e($old->referral_bonus_percent ?? $level->referral_bonus_percent) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">افزایش سقف تسک روزانه</label>
                    <input type="number" name="daily_task_limit_bonus" class="form-control" min="0" value="<?= e($old->daily_task_limit_bonus ?? $level->daily_task_limit_bonus) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">افزایش سقف برداشت</label>
                    <input type="number" name="withdrawal_limit_bonus" class="form-control" min="0" value="<?= e($old->withdrawal_limit_bonus ?? $level->withdrawal_limit_bonus) ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="priority_support" value="0">
                        <input type="checkbox" name="priority_support" value="1" class="form-check-input" <?= ($old->priority_support ?? $level->priority_support) ? 'checked' : '' ?>>
                        <label class="form-check-label">پشتیبانی اولویت‌دار</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="special_badge" value="0">
                        <input type="checkbox" name="special_badge" value="1" class="form-check-input" <?= ($old->special_badge ?? $level->special_badge) ? 'checked' : '' ?>>
                        <label class="form-check-label">نشان ویژه</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3 mb-4">
        <a href="<?= url('/admin/levels') ?>" class="btn btn-outline-secondary">انصراف</a>
        <button type="submit" class="btn btn-primary">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">save</i> ذخیره تغییرات
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>