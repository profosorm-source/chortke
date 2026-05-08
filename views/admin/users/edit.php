<?php
$title = 'ویرایش کاربر';
$layout = 'admin';
ob_start();
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--orange"><i class="material-icons">manage_accounts</i></div>
    <div>
      <h1 class="bx-page-header__title">ویرایش کاربر</h1>
      <p class="bx-page-header__sub"><?= e($user->full_name ?? '—') ?> · <?= e($user->email ?? '') ?></p>
    </div>
  </div>
  <a href="<?= url('/admin/users') ?>" class="btn btn-secondary btn-sm"><i class="material-icons">arrow_forward</i>بازگشت</a>
</div>

<div class="bx-form-layout">

  <!-- FORM CARD -->
  <div class="bx-form-layout__main">
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">edit</i><h6>اطلاعات کاربر</h6>
      </div>
      <div class="bx-info-card__body">
        <form id="editUserForm">
          <?= csrf_field() ?>
          <div class="bx-form-grid-2">
            <div class="bx-field-group">
              <label>نام کامل <span class="bx-required">*</span></label>
              <input type="text" name="full_name" class="bx-input" value="<?= e($user->full_name) ?>" required>
              <div class="bx-invalid-msg"></div>
            </div>
            <div class="bx-field-group">
              <label>ایمیل <span class="bx-required">*</span></label>
              <input type="email" name="email" class="bx-input" value="<?= e($user->email) ?>" required>
              <div class="bx-invalid-msg"></div>
            </div>
            <div class="bx-field-group">
              <label>رمز عبور جدید <span class="bx-optional">(اختیاری)</span></label>
              <input type="password" name="password" class="bx-input" placeholder="خالی = بدون تغییر">
              <div class="bx-invalid-msg"></div>
            </div>
            <div class="bx-field-group">
              <label>نقش <span class="bx-required">*</span></label>
              <select name="role" class="bx-input" required>
                <option value="user"    <?= $user->role==='user'?'selected':'' ?>>کاربر عادی</option>
                <option value="support" <?= $user->role==='support'?'selected':'' ?>>پشتیبان</option>
                <option value="admin"   <?= $user->role==='admin'?'selected':'' ?>>مدیر</option>
              </select>
              <div class="bx-invalid-msg"></div>
            </div>
            <div class="bx-field-group">
              <label>وضعیت <span class="bx-required">*</span></label>
              <select name="status" class="bx-input" required>
                <option value="active"    <?= $user->status==='active'?'selected':'' ?>>فعال</option>
                <option value="inactive"  <?= $user->status==='inactive'?'selected':'' ?>>غیرفعال</option>
                <option value="suspended" <?= $user->status==='suspended'?'selected':'' ?>>تعلیق</option>
                <option value="banned"    <?= $user->status==='banned'?'selected':'' ?>>مسدود</option>
              </select>
              <div class="bx-invalid-msg"></div>
            </div>
          </div>
          <div class="bx-form-actions">
            <button type="submit" class="btn btn-primary">
              <i class="material-icons">save</i>به‌روزرسانی
            </button>
            <a href="<?= url('/admin/users') ?>" class="btn btn-secondary">انصراف</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- SIDEBAR: User Stats -->
  <div class="bx-form-layout__side">
    <div class="bx-info-card">
      <div class="bx-info-card__header"><i class="material-icons">info</i><h6>اطلاعات حساب</h6></div>
      <div class="bx-info-card__body bx-info-card__body--p0">
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">شناسه</span>
          <code>#<?= e($user->id) ?></code>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">موجودی کیف پول</span>
          <span class="bx-td-amount bx-td-amount--pos"><?= number_format((int)($user->wallet_balance ?? 0)) ?> تومان</span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">وضعیت KYC</span>
          <span class="bx-badge <?= $user->kyc_status==='verified'?'badge-success':'badge-warning' ?>">
            <?= ['verified'=>'تأیید شده','pending'=>'در انتظار','rejected'=>'رد شده','none'=>'ندارد'][$user->kyc_status ?? 'none'] ?? '—' ?>
          </span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">تاریخ ثبت‌نام</span>
          <span><?= to_jalali($user->created_at ?? '') ?></span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">آخرین ورود</span>
          <span><?= $user->last_login ? to_jalali($user->last_login) : '—' ?></span>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
document.getElementById('editUserForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  const data = Object.fromEntries(formData.entries());
  document.querySelectorAll('.bx-input.is-invalid').forEach(el => el.classList.remove('is-invalid'));
  document.querySelectorAll('.bx-invalid-msg').forEach(el => el.textContent = '');
  try {
    const response = await fetch('<?= url("/admin/users/" . $user->id . "/update") ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': data._token },
      body: JSON.stringify(data)
    });
    const result = await response.json();
    if (result.success) {
      notyf.success(result.message);
      if (result.redirect) setTimeout(() => window.location.href = result.redirect, 1000);
    } else {
      if (result.errors) {
        Object.keys(result.errors).forEach(field => {
          const input = document.querySelector(`[name="${field}"]`);
          if (input) {
            input.classList.add('is-invalid');
            input.nextElementSibling.textContent = result.errors[field][0];
          }
        });
      }
      notyf.error(result.message || 'خطا در به‌روزرسانی');
    }
  } catch { notyf.error('خطا در ارتباط با سرور'); }
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
