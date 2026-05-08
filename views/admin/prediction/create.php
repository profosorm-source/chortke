<?php ob_start(); ?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="page-title mb-1">
        <span class="material-icons text-primary" style="vertical-align:middle;">add_circle</span>
        تعریف بازی جدید
      </h4>
      <p class="text-muted mb-0" style="font-size:12px;">کاربران بر روی نتیجه بازی USDT شرط می‌بندند</p>
    </div>
    <a href="<?= url('/admin/prediction') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
    </a>
  </div>

  <?php if($errors = session_flash('errors')): ?>
    <div class="alert alert-danger">
      <ul class="mb-0 ps-3">
        <?php foreach((array)$errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php $old = session_flash('old') ?? []; ?>

  <div class="row justify-content-center">
    <div class="col-md-8">
      <div class="card">
        <div class="card-body">
          <form method="POST" action="<?= url('/admin/prediction/store') ?>">
            <?= csrf_field() ?>

            <div class="mb-3">
              <label class="form-label fw-semibold">عنوان بازی <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control"
                     placeholder="مثال: نیمه‌نهایی جام جهانی ۲۰۲۶ — ایران vs آرژانتین"
                     value="<?= e($old['title'] ?? '') ?>" required maxlength="200">
            </div>

            <div class="row mb-3">
              <div class="col-md-5">
                <label class="form-label fw-semibold">تیم خانه <span class="text-danger">*</span></label>
                <input type="text" name="team_home" class="form-control"
                       placeholder="مثال: ایران" value="<?= e($old['team_home'] ?? '') ?>" required maxlength="100">
              </div>
              <div class="col-md-2 d-flex align-items-end justify-content-center pb-2">
                <span class="badge bg-secondary px-3 py-2">VS</span>
              </div>
              <div class="col-md-5">
                <label class="form-label fw-semibold">تیم مهمان <span class="text-danger">*</span></label>
                <input type="text" name="team_away" class="form-control"
                       placeholder="مثال: ژاپن" value="<?= e($old['team_away'] ?? '') ?>" required maxlength="100">
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">نوع ورزش <span class="text-danger">*</span></label>
                <select name="sport_type" class="form-select" required>
                  <?php foreach($sportTypes as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($old['sport_type'] ?? 'football') === $k ? 'selected' : '' ?>><?= $v ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">درصد کمیسیون سایت</label>
                <div class="input-group">
                  <input type="number" name="commission_percent" class="form-control"
                         value="<?= e($old['commission_percent'] ?? setting('prediction_commission_percent', 5)) ?>"
                         min="0" max="30" step="0.5">
                  <span class="input-group-text">٪</span>
                </div>
                <small class="text-muted">از کل استخر کسر می‌شود</small>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">تاریخ و ساعت بازی <span class="text-danger">*</span></label>
                <input type="datetime-local" name="match_date" class="form-control"
                       value="<?= e($old['match_date'] ?? '') ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">ددلاین ثبت شرط <span class="text-danger">*</span></label>
                <input type="datetime-local" name="bet_deadline" class="form-control"
                       value="<?= e($old['bet_deadline'] ?? '') ?>" required>
                <small class="text-muted">معمولاً ۳۰–۶۰ دقیقه قبل از بازی</small>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">حداقل شرط (USDT)</label>
                <input type="number" name="min_bet_usdt" class="form-control"
                       value="<?= e($old['min_bet_usdt'] ?? '1') ?>" min="0.1" step="0.1">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">حداکثر شرط (USDT)</label>
                <input type="number" name="max_bet_usdt" class="form-control"
                       value="<?= e($old['max_bet_usdt'] ?? '1000') ?>" min="1" step="1">
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">توضیحات (اختیاری)</label>
              <textarea name="description" class="form-control" rows="2"
                        placeholder="اطلاعات تکمیلی درباره بازی..."><?= e($old['description'] ?? '') ?></textarea>
            </div>

            <div class="alert alert-info d-flex gap-2 align-items-start" style="font-size:13px;">
              <span class="material-icons text-info" style="font-size:18px;">info</span>
              <div>
                <strong>نحوه کار (Pari-Mutuel Pool):</strong><br>
                همه شرط‌ها یک استخر مشترک تشکیل می‌دهند. پس از پایان بازی، ادمین نتیجه را ثبت می‌کند و
                سیستم <strong>به صورت خودکار</strong> استخر را (پس از کسر کمیسیون) بین برندگان به نسبت
                مبلغ شرطشان تقسیم می‌کند. اگر برنده‌ای نباشد، شرط‌ها برگشت داده می‌شوند.
              </div>
            </div>

            <div class="d-flex gap-2 justify-content-end">
              <a href="<?= url('/admin/prediction') ?>" class="btn btn-outline-secondary">انصراف</a>
              <button type="submit" class="btn btn-primary">
                <span class="material-icons" style="font-size:16px;vertical-align:middle;">save</span>
                ثبت بازی
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
