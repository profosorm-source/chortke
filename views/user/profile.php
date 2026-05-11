<?php
/**
 * صفحه پروفایل کاربر
 * شامل: اطلاعات شخصی + آواتار + تغییر رمز عبور
 */

$title = 'پروفایل من';
$currentPage = 'profile';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-profile.css') ?>">


<link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/vendor/sweetalert2/sweetalert2.min.css') ?>">


<div class="profile-grid">
<!-- کارت آواتار -->
<div class="profile-card">
    <div class="profile-card-header">
        <h5>
            <i class="material-icons">account_circle</i>
            تصویر پروفایل
        </h5>
    </div>
    <div class="profile-card-body">
        <div class="avatar-section">
            <div class="avatar-wrapper">
                <img id="avatarPreview" 
                     src="<?= asset('uploads/avatars/' . ($user->avatar ?: 'default-avatar.png')) ?>" 
                     alt="<?= e($user->full_name) ?>" 
                     class="avatar-image">
                
                <!-- دکمه تغییر تصویر (روی عکس) -->
                <label for="avatarInput" class="avatar-overlay" title="تغییر تصویر">
                    <i class="material-icons">camera_alt</i>
                </label>
                
                <!-- Input مخفی برای انتخاب فایل -->
                <input type="file" 
                       id="avatarInput" 
                       name="avatar"
                       accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" 
                       style="display: none;">
                
                <!-- لودر -->
                <div class="avatar-preview-loader" id="avatarLoader">
                    <i class="material-icons">hourglass_empty</i>
                </div>
            </div>
            
            <p class="avatar-info">
                <i class="material-icons" style="font-size: 14px; vertical-align: middle;">info</i>
                فرمت‌های مجاز: JPG, PNG, GIF, WEBP | حداکثر حجم: 2 مگابایت
            </p>
            
            <div class="avatar-actions">
                <!-- دکمه انتخاب تصویر -->
                <button type="button" class="btn btn-upload" onclick="document.getElementById('avatarInput').click()">
                    <i class="material-icons" style="font-size: 14px; vertical-align: middle;">upload</i>
                    انتخاب تصویر جدید
                </button>
                
                <!-- دکمه حذف (فقط اگر آواتار سفارشی دارد) -->
                <?php if ($user->avatar && $user->avatar !== 'default-avatar.png'): ?>
                <button type="button" class="btn btn-delete-avatar" onclick="deleteAvatar()">
                    <i class="material-icons" style="font-size: 14px; vertical-align: middle;">delete</i>
                    حذف تصویر فعلی
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- کارت اطلاعات شخصی -->
<div class="profile-card">
    <div class="profile-card-header">
        <h5>
            <i class="material-icons">person</i>
            اطلاعات شخصی
        </h5>
    </div>
    <div class="profile-card-body">
        <form method="POST" action="<?= url('profile/update') ?>">
            <?= csrf_field() ?>
            
            <!-- اطلاعات پایه -->
            <div class="form-section">
                <div class="form-section-title">
                    <i class="material-icons">badge</i>
                    اطلاعات پایه
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">
                                <span class="required">*</span>
                                نام کامل
                            </label>
                            <input type="text" 
                                   name="full_name" 
                                   class="form-control" 
                                   value="<?= e($user->full_name) ?>" 
                                   placeholder="نام و نام خانوادگی خود را وارد کنید"
                                   required>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">ایمیل</label>
                            <input type="email" 
                                   value="<?= e($user->email) ?>" 
                                   class="form-control" 
                                   readonly>
                            <small class="form-text">
                                <i class="material-icons" style="font-size: 12px; vertical-align: middle;">lock</i>
                                ایمیل قابل تغییر نیست
                            </small>
                            <?php if (empty($user->email_verified_at)): ?>
                            <div class="mt-2" id="verify-email">
                                <span class="badge bg-warning text-dark">
                                    <i class="material-icons" style="font-size:13px;vertical-align:middle;">warning</i>
                                    تأیید نشده
                                </span>
                                <form method="POST" action="<?= url('/email/resend-verification') ?>" class="d-inline ms-2">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="email" value="<?= e($user->email) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        ارسال کد تأیید
                                    </button>
                                </form>
                                <a href="<?= url('/email/verify-code?email=' . urlencode($user->email)) ?>" class="btn btn-sm btn-outline-secondary ms-1">
                                    وارد کردن کد
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="mt-1">
                                <span class="badge bg-success">
                                    <i class="material-icons" style="font-size:13px;vertical-align:middle;">verified</i>
                                    تأیید شده
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">شماره موبایل</label>
                            <input type="text" 
                                   name="mobile" 
                                   class="form-control" 
                                   value="<?= e($user->mobile ?? '') ?>" 
                                   pattern="09[0-9]{9}" 
                                   placeholder="09123456789"
                                   maxlength="11">
                            <small class="form-text">مثال: 09123456789</small>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">کد ملی</label>
                            <input type="text" 
                                   name="national_id" 
                                   class="form-control" 
                                   value="<?= e($user->national_id ?? '') ?>" 
                                   pattern="[0-9]{10}" 
                                   placeholder="1234567890"
                                   maxlength="10">
                            <small class="form-text">10 رقم بدون فاصله</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- اطلاعات تکمیلی -->
            <div class="form-section">
                <div class="form-section-title">
                    <i class="material-icons">description</i>
                    اطلاعات تکمیلی
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">تاریخ تولد</label>
                            <input type="date" 
                                   name="birth_date" 
                                   class="form-control" 
                                   value="<?= e($user->birth_date ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">جنسیت</label>
                            <select name="gender" class="form-select">
                                <option value="">انتخاب کنید</option>
                                <option value="male" <?= ($user->gender ?? '') === 'male' ? 'selected' : '' ?>>مرد</option>
                                <option value="female" <?= ($user->gender ?? '') === 'female' ? 'selected' : '' ?>>زن</option>
                                <option value="other" <?= ($user->gender ?? '') === 'other' ? 'selected' : '' ?>>سایر</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label class="form-label">آدرس</label>
                            <textarea name="address" 
                                      class="form-control" 
                                      rows="3" 
                                      placeholder="آدرس کامل پستی خود را وارد کنید"><?= e($user->address ?? '') ?></textarea>
                            <small class="form-text">این اطلاعات برای ارسال پستی استفاده می‌شود</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- اطلاعات سیستمی -->
            <div class="info-box">
                <div class="info-box-title">
                    <i class="material-icons">info</i>
                    اطلاعات حساب کاربری
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>تاریخ عضویت:</strong> <?= e($user->created_at ? jdate($user->created_at) : '-') ?></p>
                        <p><strong>آخرین ورود:</strong> <?= e($user->last_login ? jdate($user->last_login) : 'هرگز') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>نقش:</strong> 
                            <span style="background: #e8f5e9; color: #4caf50; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                                <?= e($user->role) ?>
                            </span>
                        </p>
                        <p><strong>وضعیت:</strong> 
                            <span style="background: <?= $user->status === 'active' ? '#e8f5e9' : '#ffebee' ?>; color: <?= $user->status === 'active' ? '#4caf50' : '#f44336' ?>; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                                <?= e($user->status) ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <button type="submit" class="btn-save">
                    <i class="material-icons">save</i>
                    ذخیره تغییرات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- کارت تغییر رمز عبور -->
<div class="profile-card card-password">
<div class="profile-card">
    <div class="profile-card-header">
        <h5>
            <i class="material-icons">lock</i>
            تغییر رمز عبور
        </h5>
    </div>
    <div class="profile-card-body">
        <form method="POST" action="<?= url('profile/change-password') ?>" id="changePasswordForm">
            <?= csrf_field() ?>
            
            <div class="form-section">
                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label class="form-label">
                                <span class="required">*</span>
                                رمز عبور فعلی
                            </label>
                            <input type="password" 
                                   name="current_password" 
                                   class="form-control" 
                                   placeholder="رمز عبور فعلی خود را وارد کنید"
                                   required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">
                                <span class="required">*</span>
                                رمز عبور جدید
                            </label>
                            <input type="password" 
                                   name="new_password" 
                                   id="newPassword"
                                   class="form-control" 
                                   placeholder="رمز عبور جدید (حداقل 8 کاراکتر)"
                                   minlength="8"
                                   required>
                            <small class="form-text">حداقل 8 کاراکتر، ترکیبی از حروف و اعداد</small>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">
                                <span class="required">*</span>
                                تکرار رمز عبور جدید
                            </label>
                            <input type="password" 
                                   name="new_password_confirmation" 
                                   id="confirmPassword"
                                   class="form-control" 
                                   placeholder="رمز عبور جدید را دوباره وارد کنید"
                                   minlength="8"
                                   required>
                        </div>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn-save">
                <i class="material-icons">vpn_key</i>
                تغییر رمز عبور
            </button>
        </form>
    </div>
</div>
</div>
</div>
<script src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>


<script>
(function () {
    // اگر قبلاً تعریف شده، دوباره نساز
    if (typeof window.showNotification === 'function') return;

    const notyf = new Notyf({
      duration: 4000,
      position: { x: 'left', y: 'top' }
    });

    window.showNotification = function (type, message) {
      // type: success | error | warning | info
      notyf.open({ type: type, message: message });
    };
  })();
(function () {
	  const input = document.getElementById('avatarInput');
  const loader = document.getElementById('avatarLoader');
  const preview = document.getElementById('avatarPreview');

  if (!input) return;

  const MAX_BYTES = 2 * 1024 * 1024; // 2MB
  const ALLOWED = ['image/jpeg','image/png','image/jpg','image/gif','image/webp'];

  function stopLoader(){ if (loader) loader.classList.remove('active'); }
  function startLoader(){ if (loader) loader.classList.add('active'); }

  input.addEventListener('change', async function (e) {
    const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;

    if (!file) return;

    // اعتبارسنجی سمت کلاینت (قبل از ارسال)
    if (!ALLOWED.includes(file.type)) {
      showNotification('error', 'فرمت فایل مجاز نیست. فقط JPG/PNG/GIF/WEBP');
      e.target.value = '';
      return;
    }

    if (file.size > MAX_BYTES) {
      showNotification('error', 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد');
      e.target.value = '';
      return;
    }

    // پیش‌نمایش
    try {
      const reader = new FileReader();
      reader.onload = (ev) => { if (preview) preview.src = ev.target.result; };
      reader.readAsDataURL(file);
    } catch (_) {}

    startLoader();

    const fd = new FormData();
    fd.append('avatar', file);

    let res;
    try {
      res = await fetch('<?= url('profile/upload-avatar') ?>', {
        method: 'POST',
        body: fd,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': '<?= csrf_token() ?>'
        }
      });
    } catch (err) {
      stopLoader();
      showNotification('error', 'خطا در ارتباط با سرور');
      e.target.value = '';
      return;
    }

    const contentType = (res.headers.get('content-type') || '').toLowerCase();
    const raw = await res.text();

    stopLoader();

    if (!contentType.includes('application/json')) {
      showNotification('error', 'پاسخ سرور نامعتبر است');
      console.log('Non-JSON response:', raw);
      e.target.value = '';
      return;
    }

    let data;
    try {
      data = JSON.parse(raw);
    } catch (err) {
      showNotification('error', 'خطا در پردازش پاسخ سرور');
      console.log('JSON parse error:', err, raw);
      e.target.value = '';
      return;
    }

    if (!data.success) {
      showNotification('error', data.message || 'آپلود ناموفق بود');
      e.target.value = '';
      return;
    }

    // موفق
    showNotification('success', data.message || 'آواتار بروزرسانی شد');

    if (data.avatar_url) {
      const t = Date.now();
      document.querySelectorAll('.user-avatar').forEach(img => img.src = data.avatar_url + '?t=' + t);
      if (preview) preview.src = data.avatar_url + '?t=' + t;
    }

    e.target.value = '';
  });
 window.deleteAvatar = async function () {
  const result = await Swal.fire({
    title: 'حذف تصویر پروفایل؟',
    text: 'این عملیات قابل بازگشت است و تصویر پیش‌فرض جایگزین می‌شود.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'بله، حذف شود',
    cancelButtonText: 'انصراف',
    reverseButtons: true
  });

  if (!result.isConfirmed) return;

  try {
    const res = await fetch('<?= url('profile/delete-avatar') ?>', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': '<?= csrf_token() ?>'
      }
    });

    const data = await res.json();

    if (data.success) {
      showNotification('success', data.message || 'حذف شد');

      if (data.avatar_url) {
        const t = Date.now();
        document.querySelectorAll('.user-avatar').forEach(img => img.src = data.avatar_url + '?t=' + t);
        const prev = document.getElementById('avatarPreview');
        if (prev) prev.src = data.avatar_url + '?t=' + t;
      }

      setTimeout(() => location.reload(), 700);
      return;
    }

    showNotification('error', data.message || 'خطا در حذف آواتار');
  } catch (e) {
    console.error(e);
    showNotification('error', 'خطا در ارتباط با سرور');
  }
}
})();
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>