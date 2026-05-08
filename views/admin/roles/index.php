<?php
$title = 'مدیریت نقش‌ها';
$layout = 'admin';
ob_start();
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--purple"><i class="material-icons">admin_panel_settings</i></div>
    <div>
      <h1 class="bx-page-header__title">مدیریت نقش‌ها</h1>
      <p class="bx-page-header__sub">تعریف نقش‌ها و تنظیم سطح دسترسی‌ها</p>
    </div>
  </div>
  <a href="<?= url('/admin/roles/create') ?>" class="btn btn-primary btn-sm">
    <i class="material-icons">add</i>نقش جدید
  </a>
</div>

<?php if ($flash = \Core\Session::getInstance()->getFlash('success')): ?>
<div class="bx-alert bx-alert--green" style="margin-bottom:20px">
  <i class="material-icons">check_circle</i><?= e($flash) ?>
</div>
<?php endif; ?>
<?php if ($flash = \Core\Session::getInstance()->getFlash('error')): ?>
<div class="bx-alert bx-alert--red" style="margin-bottom:20px">
  <i class="material-icons">error</i><?= e($flash) ?>
</div>
<?php endif; ?>

<div class="bx-table-card">
  <div class="bx-table-card__header"><h3><i class="material-icons">security</i>لیست نقش‌ها</h3></div>
  <div class="bx-table-wrap">
    <table class="bx-table">
      <thead>
        <tr>
          <th style="width:42px">#</th>
          <th>نام نقش</th>
          <th>شناسه</th>
          <th>توضیحات</th>
          <th>کاربران</th>
          <th>وضعیت</th>
          <th>نوع</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($roles)): ?>
        <tr><td colspan="8"><div class="bx-empty"><i class="material-icons">folder_off</i><p>هیچ نقشی یافت نشد</p></div></td></tr>
      <?php else: ?>
        <?php foreach ($roles as $index => $role): ?>
        <tr id="role-row-<?= e($role->id) ?>">
          <td class="bx-td-num"><?= $index + 1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <?php if ($role->is_system): ?>
              <span style="width:6px;height:6px;border-radius:50%;background:var(--gold);flex-shrink:0"></span>
              <?php endif; ?>
              <strong style="font-size:13px;color:var(--fg-primary)"><?= e($role->name) ?></strong>
            </div>
          </td>
          <td><code style="font-size:11px"><?= e($role->slug) ?></code></td>
          <td style="font-size:12px;color:var(--fg-muted);max-width:200px"><?= e($role->description ?? '—') ?></td>
          <td><span class="bx-badge badge-muted"><?= number_format($role->user_count) ?> نفر</span></td>
          <td>
            <?php if ($role->is_active): ?>
              <span class="bx-badge badge-success">فعال</span>
            <?php else: ?>
              <span class="bx-badge badge-danger">غیرفعال</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($role->is_system): ?>
              <span class="bx-badge badge-warning"><i class="material-icons" style="font-size:10px!important">lock</i>سیستمی</span>
            <?php else: ?>
              <span class="bx-badge badge-success">سفارشی</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="bx-action-group">
              <a href="<?= url('/admin/roles/' . $role->id . '/edit') ?>" class="bx-action-btn bx-action-btn--edit" title="ویرایش">
                <i class="material-icons">edit</i>
              </a>
              <?php if (!$role->is_system): ?>
              <button class="bx-action-btn bx-action-btn--warn btn-toggle-role" data-id="<?= e($role->id) ?>" data-status="<?= e($role->is_active) ?>" title="<?= $role->is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>">
                <i class="material-icons"><?= $role->is_active ? 'toggle_on' : 'toggle_off' ?></i>
              </button>
              <button class="bx-action-btn bx-action-btn--danger btn-delete-role" data-id="<?= e($role->id) ?>" data-name="<?= e($role->name) ?>" data-users="<?= e($role->user_count) ?>" title="حذف">
                <i class="material-icons">delete</i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Guide -->
<div class="bx-info-card">
  <div class="bx-info-card__header"><i class="material-icons">info</i><h6>راهنما</h6></div>
  <div class="bx-info-card__body">
    <ul style="font-size:13px;color:var(--fg-secondary);padding-right:18px;line-height:2">
      <li>نقش‌های <strong style="color:var(--gold)">سیستمی</strong> (مدیر کل، مدیر، پشتیبانی، کاربر) قابل حذف نیستند.</li>
      <li>برای تغییر دسترسی‌های هر نقش، روی <strong>ویرایش</strong> کلیک کنید.</li>
      <li>نقش «مدیر کل» به‌صورت پیش‌فرض تمام دسترسی‌ها را دارد.</li>
      <li>نقش‌هایی که <strong>کاربر فعال</strong> دارند، قابل حذف نیستند.</li>
    </ul>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.btn-delete-role').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const id=this.dataset.id, name=this.dataset.name, users=parseInt(this.dataset.users);
      if (users > 0) { Swal.fire({title:'عملیات غیرممکن',text:'این نقش '+users+' کاربر فعال دارد.',icon:'warning',confirmButtonText:'متوجه شدم'}); return; }
      Swal.fire({title:'حذف نقش',text:'آیا از حذف نقش «'+name+'» اطمینان دارید؟',icon:'warning',showCancelButton:true,confirmButtonColor:'#f44336',cancelButtonColor:'#999',confirmButtonText:'بله، حذف شود',cancelButtonText:'انصراف'})
      .then(function(result) {
        if (result.isConfirmed) {
          fetch('<?= url('/admin/roles/') ?>'+id+'/delete',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},body:JSON.stringify({csrf_token:'<?= csrf_token() ?>'})})
          .then(r=>r.json()).then(data=>{if(data.success){notyf.success(data.message);var row=document.getElementById('role-row-'+id);if(row)row.remove();}else{notyf.error(data.message);}});
        }
      });
    });
  });
  document.querySelectorAll('.btn-toggle-role').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const id=this.dataset.id, btnEl=this;
      fetch('<?= url('/admin/roles/') ?>'+id+'/toggle',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},body:JSON.stringify({csrf_token:'<?= csrf_token() ?>'})})
      .then(r=>r.json()).then(data=>{
        if(data.success){
          notyf.success(data.message);
          btnEl.dataset.status=data.new_status;
          btnEl.querySelector('i').textContent=data.new_status?'toggle_on':'toggle_off';
          var row=document.getElementById('role-row-'+id);
          if(row){var badge=row.querySelector('td:nth-child(6) span');if(data.new_status){badge.className='bx-badge badge-success';badge.textContent='فعال';}else{badge.className='bx-badge badge-danger';badge.textContent='غیرفعال';}}
        }else notyf.error(data.message);
      });
    });
  });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
