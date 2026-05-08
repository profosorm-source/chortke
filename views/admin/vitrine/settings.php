<?php $title = 'تنظیمات ویترین'; $layout = 'admin'; ob_start(); ?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="page-title mb-1">
        <span class="material-icons text-primary align-middle">settings</span>
        تنظیمات ویترین
      </h4>
      <p class="text-muted mb-0" style="font-size:12px;">
        پیکربندی کامل سرویس خرید و فروش متنی
      </p>
    </div>
    <a href="<?= url('/admin/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</span> مدیریت آگهی‌ها
    </a>
  </div>

  <form action="<?= url('/admin/vitrine/settings/save') ?>" method="POST">
    <?= csrf_field() ?>

    <div class="row g-3">

      <!-- فعال/غیرفعال کردن -->
      <div class="col-12">
        <div class="card border-<?= $vitrineEnabled ? 'success' : 'danger' ?>">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h6 class="fw-bold mb-1">وضعیت سرویس ویترین</h6>
                <p class="text-muted small mb-0">
                  غیرفعال کردن باعث می‌شود کاربران نتوانند به ویترین دسترسی داشته باشند.
                  معاملات در حال انجام (escrow) تحت تأثیر قرار نمی‌گیرند.
                </p>
              </div>
              <div class="d-flex align-items-center gap-3">
                <span class="badge bg-<?= $vitrineEnabled ? 'success' : 'danger' ?> fs-6 px-3 py-2">
                  <?= $vitrineEnabled ? 'فعال' : 'غیرفعال' ?>
                </span>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox"
                         name="vitrine_enabled" value="1"
                         id="vitrineToggle" style="width:3rem;height:1.5rem;"
                         <?= $vitrineEnabled ? 'checked' : '' ?>>
                  <label class="form-check-label" for="vitrineToggle"></label>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- مالی -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">
            <h6 class="card-title mb-0">
              <span class="material-icons align-middle" style="font-size:18px;">payments</span>
              تنظیمات مالی
            </h6>
          </div>
          <div class="card-body">

            <div class="mb-3">
              <label class="form-label fw-medium">
                کمیسیون ویترین (٪)
                <span class="badge bg-info ms-1" style="font-size:11px;">از مبلغ معامله</span>
              </label>
              <div class="input-group">
                <input type="number" name="vitrine_commission_percent"
                       class="form-control" min="0" max="50" step="0.1"
                       value="<?= e($commission) ?>">
                <span class="input-group-text">٪</span>
              </div>
              <div class="form-text">مقدار پیش‌فرض: ۵٪</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">حداقل قیمت آگهی (USDT)</label>
              <div class="input-group">
                <input type="number" name="vitrine_min_price_usdt"
                       class="form-control" min="0" step="0.01"
                       value="<?= e($minPrice) ?>">
                <span class="input-group-text">USDT</span>
              </div>
            </div>

            <div class="mb-0">
              <label class="form-label fw-medium">حداکثر قیمت آگهی (USDT)</label>
              <div class="input-group">
                <input type="number" name="vitrine_max_price_usdt"
                       class="form-control" min="0" step="0.01"
                       value="<?= e($maxPrice) ?>">
                <span class="input-group-text">USDT</span>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- عملیاتی -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">
            <h6 class="card-title mb-0">
              <span class="material-icons align-middle" style="font-size:18px;">tune</span>
              تنظیمات عملیاتی
            </h6>
          </div>
          <div class="card-body">

            <div class="mb-3">
              <label class="form-label fw-medium">
                مدت زمان تست escrow (روز)
              </label>
              <div class="input-group">
                <input type="number" name="vitrine_escrow_days"
                       class="form-control" min="1" max="30"
                       value="<?= e($escrowDays) ?>">
                <span class="input-group-text">روز</span>
              </div>
              <div class="form-text">
                پس از این مدت، اگر خریدار تایید نکند، وجه خودکار به فروشنده پرداخت می‌شود.
                مقدار پیش‌فرض: ۳ روز
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">حداکثر آگهی فعال هر کاربر</label>
              <input type="number" name="vitrine_max_active_per_user"
                     class="form-control" min="1" max="100"
                     value="<?= e($maxPerUser) ?>">
            </div>

            <div class="mb-0">
              <label class="form-label fw-medium">الزام KYC برای معامله</label>
              <div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio"
                         name="vitrine_kyc_required" value="1" id="kycYes"
                         <?= $kycRequired ? 'checked' : '' ?>>
                  <label class="form-check-label" for="kycYes">
                    <span class="text-success fw-medium">فعال</span> — فقط کاربران KYC‌شده
                  </label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio"
                         name="vitrine_kyc_required" value="0" id="kycNo"
                         <?= !$kycRequired ? 'checked' : '' ?>>
                  <label class="form-check-label" for="kycNo">
                    <span class="text-warning fw-medium">غیرفعال</span> — همه کاربران
                  </label>
                </div>
              </div>
              <div class="form-text text-danger small">
                توصیه می‌شود KYC همیشه فعال باشد.
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- راهنمای cron -->
      <div class="col-12">
        <div class="card bg-light border-0">
          <div class="card-body">
            <h6 class="fw-medium mb-2">
              <span class="material-icons align-middle" style="font-size:18px;">schedule</span>
              تنظیم Cron Job (تایید خودکار اسکرو)
            </h6>
            <p class="small text-muted mb-2">
              برای تایید خودکار اسکروهای منقضی، این دستور را در crontab سرور اضافه کنید:
            </p>
            <code class="d-block bg-dark text-light rounded p-3 small" dir="ltr">
              0 */6 * * * /usr/bin/php <?= base_path('storage/cron/vitrine_auto_confirm.php') ?> >> /var/log/vitrine-cron.log 2>&1
            </code>
            <div class="small text-muted mt-2">
              هر ۶ ساعت یکبار اجرا می‌شود — آگهی‌هایی که مهلتشان تمام شده و خریدار تایید نکرده را به‌صورت خودکار تسویه می‌کند.
            </div>
          </div>
        </div>
      </div>

      <!-- دکمه ذخیره -->
      <div class="col-12">
        <button type="submit" class="btn btn-primary px-5">
          <span class="material-icons align-middle" style="font-size:18px;">save</span>
          ذخیره تنظیمات
        </button>
      </div>

    </div>
  </form>
</div>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
