<?php
$title = 'تنظیمات سیستم';
$layout = 'admin';
ob_start();
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--purple"><i class="material-icons">settings</i></div>
    <div>
      <h1 class="bx-page-header__title">تنظیمات سیستم</h1>
      <p class="bx-page-header__sub">پیکربندی و مدیریت تنظیمات پنل</p>
    </div>
  </div>
</div>

<!-- SETTINGS LAYOUT -->
<div class="bx-settings-layout">

  <!-- CATEGORY TABS -->
  <div class="bx-settings-sidebar">
    <?php foreach ($categories as $key => $label): ?>
    <a href="<?= url('/admin/settings?category=' . $key) ?>"
       class="bx-settings-tab <?= $currentCategory === $key ? 'bx-settings-tab--active' : '' ?>">
      <i class="material-icons"><?= [
        'general'=>'tune', 'email'=>'email', 'payment'=>'payment', 'images'=>'image',
        'security'=>'security', 'social'=>'share', 'sms'=>'sms', 'referral'=>'group_add'
      ][$key] ?? 'settings' ?></i>
      <?= e($label) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- SETTINGS CONTENT -->
  <div class="bx-settings-content">
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">tune</i>
        <h6><?= e($categories[$currentCategory] ?? 'تنظیمات') ?></h6>
        <span class="bx-badge badge-muted" style="margin-right:auto"><?= count($settings ?? []) ?> مورد</span>
      </div>
      <div class="bx-info-card__body bx-info-card__body--p0">

        <?php foreach ($settings as $setting): ?>
        <div class="bx-setting-row">
          <div class="bx-setting-row__meta">
            <p class="bx-setting-row__key"><?= e($setting->key) ?></p>
            <?php if ($setting->description): ?>
            <p class="bx-setting-row__desc"><?= e($setting->description) ?></p>
            <?php endif; ?>
          </div>
          <div class="bx-setting-row__control">
            <?php if ($setting->category === 'images'): ?>
              <div class="bx-img-upload">
                <?php if (!empty($setting->value)): ?>
                <div class="bx-img-upload__preview" id="preview_<?= e($setting->id) ?>">
                  <img src="<?= url($setting->value) ?>" alt="<?= e($setting->key) ?>">
                  <button type="button" class="bx-img-upload__remove" onclick="removeImage(<?= (int)$setting->id ?>)">
                    <i class="material-icons">delete</i>
                  </button>
                </div>
                <?php else: ?>
                <div class="bx-img-upload__empty" id="preview_<?= e($setting->id) ?>">
                  <i class="material-icons">cloud_upload</i><span>آپلود نشده</span>
                </div>
                <?php endif; ?>
                <input type="file" class="bx-img-upload__input" id="file_<?= e($setting->id) ?>"
                       accept="image/*" onchange="uploadImage(<?= (int)$setting->id ?>, this)">
              </div>
            <?php elseif ($setting->type === 'boolean'): ?>
              <div class="bx-setting-row__bool-wrap">
                <select class="bx-input" id="setting_<?= e($setting->id) ?>" data-key="<?= e($setting->key) ?>">
                  <option value="1" <?= $setting->value == '1' ? 'selected' : '' ?>>✅ فعال</option>
                  <option value="0" <?= $setting->value == '0' ? 'selected' : '' ?>>❌ غیرفعال</option>
                </select>
              </div>
            <?php elseif ($setting->type === 'text'): ?>
              <textarea class="bx-input" rows="3" id="setting_<?= e($setting->id) ?>" data-key="<?= e($setting->key) ?>"><?= e($setting->value) ?></textarea>
            <?php else: ?>
              <input type="text" class="bx-input" id="setting_<?= e($setting->id) ?>" data-key="<?= e($setting->key) ?>" value="<?= e($setting->value) ?>">
            <?php endif; ?>
          </div>
          <div class="bx-setting-row__action">
            <?php if ($setting->category !== 'images'): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="updateSetting(<?= (int)$setting->id ?>)">
              <i class="material-icons">save</i>ذخیره
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

      </div>
    </div>
  </div>

</div>

<script>
async function updateSetting(settingId) {
  const el = document.getElementById('setting_' + settingId);
  if (!el) return;
  try {
    const response = await fetch(`/admin/settings/${settingId}/update`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: settingId, key: el.dataset.key, value: el.value })
    });
    const result = await response.json();
    if (result.success) notyf.success(result.message || 'ذخیره شد');
    else notyf.error(result.message || 'خطا');
  } catch { notyf.error('خطا در ارتباط با سرور'); }
}

async function uploadImage(settingId, input) {
  const file = input.files[0];
  if (!file) return;
  if (!file.type.startsWith('image/')) { notyf.error('لطفاً یک تصویر انتخاب کنید'); return; }
  if (file.size > 2 * 1024 * 1024) { notyf.error('حجم فایل نباید بیشتر از 2MB باشد'); return; }
  const formData = new FormData();
  formData.append('image', file);
  formData.append('setting_id', settingId);
  input.disabled = true;
  try {
    const response = await fetch('<?= url('/admin/settings/upload-image') ?>', { method:'POST', headers:{'X-CSRF-TOKEN':'<?= csrf_token() ?>'}, credentials:'same-origin', body:formData });
    const result = await response.json();
    input.disabled = false;
    if (result.success) {
      notyf.success(result.message || 'آپلود شد');
      const previewDiv = document.getElementById('preview_' + settingId);
      previewDiv.className = 'bx-img-upload__preview';
      previewDiv.innerHTML = `<img src="${result.url}" alt="تصویر"><button type="button" class="bx-img-upload__remove" onclick="removeImage(${settingId})"><i class="material-icons">delete</i></button>`;
      input.value = '';
    } else notyf.error(result.message || 'خطا');
  } catch { input.disabled=false; notyf.error('خطا'); }
}

async function removeImage(settingId) {
  if (!confirm('آیا از حذف این تصویر اطمینان دارید؟')) return;
  try {
    const response = await fetch('<?= url('/admin/settings/remove-image') ?>', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'}, credentials:'same-origin', body:JSON.stringify({setting_id:settingId}) });
    const result = await response.json();
    if (result.success) {
      notyf.success(result.message || 'حذف شد');
      const previewDiv = document.getElementById('preview_' + settingId);
      previewDiv.className = 'bx-img-upload__empty';
      previewDiv.innerHTML = '<i class="material-icons">cloud_upload</i><span>آپلود نشده</span>';
    } else notyf.error(result.message || 'خطا');
  } catch { notyf.error('خطا'); }
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
