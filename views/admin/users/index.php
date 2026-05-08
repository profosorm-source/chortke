<?php
ob_start();
$title = 'مدیریت کاربران';
$layout = 'admin';
$search       = $search       ?? e($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8');
$roleFilter   = $roleFilter   ?? e($_GET['role']   ?? '', ENT_QUOTES, 'UTF-8');
$statusFilter = $statusFilter ?? e($_GET['status'] ?? '', ENT_QUOTES, 'UTF-8');
$gradients   = ['linear-gradient(135deg,#5b8af5,#7c3aed)','linear-gradient(135deg,#10b981,#06b6d4)','linear-gradient(135deg,#f59e0b,#ef4444)','linear-gradient(135deg,#a855f7,#ec4899)','linear-gradient(135deg,#06b6d4,#3b82f6)'];
$roleColors  = ['admin'=>'badge-danger','support'=>'badge-warning','user'=>'badge-muted'];
$roleNames   = ['admin'=>'مدیر','support'=>'پشتیبان','user'=>'کاربر'];
$statusColors= ['active'=>'badge-success','inactive'=>'badge-muted','suspended'=>'badge-warning','banned'=>'badge-danger'];
$statusNames = ['active'=>'فعال','inactive'=>'غیرفعال','suspended'=>'تعلیق','banned'=>'بن'];
$rowNum = (($currentPage ?? 1) - 1) * 20;
?>

<!-- ══ PAGE HEADER ══ -->
<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon"><i class="material-icons">group</i></div>
    <div>
      <h1 class="bx-page-header__title">مدیریت کاربران</h1>
      <p class="bx-page-header__sub">مجموع <strong><?= number_format($total ?? 0) ?></strong> کاربر ثبت‌نام شده</p>
    </div>
  </div>
  <div class="bx-page-header__actions">
    <a href="<?= url('/admin/export?type=users') ?>" class="btn btn-secondary btn-sm">
      <i class="material-icons">file_download</i>خروجی Excel
    </a>
    <a href="<?= url('/admin/users/create') ?>" class="btn btn-primary btn-sm">
      <i class="material-icons">person_add</i>کاربر جدید
    </a>
  </div>
</div>

<!-- ══ STATS ══ -->
<div class="bx-stats-row">
  <div class="bx-stat bx-stat--gold">
    <div class="bx-stat__icon"><i class="material-icons">group</i></div>
    <div class="bx-stat__body">
      <span class="bx-stat__num"><?= number_format($userStats->total_count ?? 0) ?></span>
      <span class="bx-stat__lbl">کل کاربران</span>
    </div>
  </div>
  <div class="bx-stat bx-stat--green">
    <div class="bx-stat__icon"><i class="material-icons">check_circle</i></div>
    <div class="bx-stat__body">
      <span class="bx-stat__num"><?= number_format($userStats->active_count ?? 0) ?></span>
      <span class="bx-stat__lbl">فعال</span>
    </div>
  </div>
  <div class="bx-stat bx-stat--orange">
    <div class="bx-stat__icon"><i class="material-icons">pause_circle</i></div>
    <div class="bx-stat__body">
      <span class="bx-stat__num"><?= number_format($userStats->suspended_count ?? 0) ?></span>
      <span class="bx-stat__lbl">تعلیق شده</span>
    </div>
  </div>
  <div class="bx-stat bx-stat--red">
    <div class="bx-stat__icon"><i class="material-icons">block</i></div>
    <div class="bx-stat__body">
      <span class="bx-stat__num"><?= number_format($userStats->banned_count ?? 0) ?></span>
      <span class="bx-stat__lbl">بن شده</span>
    </div>
  </div>
</div>

<!-- ══ FILTER ══ -->
<form method="GET" action="<?= url('/admin/users') ?>">
<div class="bx-filter-bar">
  <div class="bx-filter-bar__fields">
    <div class="bx-filter-bar__search">
      <i class="material-icons">search</i>
      <input type="text" name="search" placeholder="جستجو نام، ایمیل، موبایل..." value="<?= e($search) ?>">
    </div>
    <select name="role" class="bx-filter-bar__select">
      <option value="">همه نقش‌ها</option>
      <option value="user"    <?= $roleFilter==='user'?'selected':'' ?>>کاربر عادی</option>
      <option value="support" <?= $roleFilter==='support'?'selected':'' ?>>پشتیبان</option>
      <option value="admin"   <?= $roleFilter==='admin'?'selected':'' ?>>مدیر</option>
    </select>
    <select name="status" class="bx-filter-bar__select">
      <option value="">همه وضعیت‌ها</option>
      <option value="active"    <?= $statusFilter==='active'?'selected':'' ?>>فعال</option>
      <option value="inactive"  <?= $statusFilter==='inactive'?'selected':'' ?>>غیرفعال</option>
      <option value="suspended" <?= $statusFilter==='suspended'?'selected':'' ?>>تعلیق</option>
      <option value="banned"    <?= $statusFilter==='banned'?'selected':'' ?>>بن شده</option>
    </select>
  </div>
  <div class="bx-filter-bar__actions">
    <button type="submit" class="btn btn-primary btn-sm"><i class="material-icons">filter_list</i>فیلتر</button>
    <?php if ($search || $roleFilter || $statusFilter): ?>
    <a href="<?= url('/admin/users') ?>" class="btn btn-secondary btn-sm"><i class="material-icons">close</i>پاک</a>
    <?php endif; ?>
  </div>
  <span class="bx-filter-bar__count">نمایش <?= count($users ?? []) ?> از <?= number_format($total ?? 0) ?></span>
</div>
</form>

<!-- ══ DATA TABLE ══ -->
<div class="bx-table-card">
  <div class="bx-table-card__header">
    <h3><i class="material-icons">list</i>لیست کاربران</h3>
  </div>
  <div class="bx-table-wrap">
    <table class="bx-table">
      <thead>
        <tr>
          <th style="width:42px">#</th>
          <th>کاربر</th>
          <th>موبایل</th>
          <th>نقش</th>
          <th>وضعیت</th>
          <th>موجودی (تومان)</th>
          <th>تاریخ عضویت</th>
          <th>آخرین ورود</th>
          <th style="width:120px">عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($users)): ?>
        <tr>
          <td colspan="9">
            <div class="bx-empty"><i class="material-icons">person_search</i><p>هیچ کاربری یافت نشد</p></div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($users as $u): $rowNum++; $g = $gradients[$u->id % count($gradients)]; ?>
        <tr>
          <td class="bx-td-num"><?= $rowNum ?></td>
          <td>
            <div class="bx-user-cell">
              <div class="bx-user-avatar" style="background:<?= $g ?>"><?= mb_substr($u->full_name ?? 'ک', 0, 1, 'UTF-8') ?></div>
              <div class="bx-user-info">
                <strong><?= e($u->full_name ?? '-') ?></strong>
                <small><?= e($u->email ?? '') ?></small>
              </div>
            </div>
          </td>
          <td class="bx-td-mono"><?= e($u->mobile ?? '-') ?></td>
          <td><span class="bx-badge <?= $roleColors[$u->role] ?? 'badge-muted' ?>"><?= $roleNames[$u->role] ?? 'کاربر' ?></span></td>
          <td>
            <div class="bx-status-cell">
              <span class="bx-status-dot bx-status-dot--<?= $u->status ?? 'inactive' ?>"></span>
              <span class="bx-badge <?= $statusColors[$u->status] ?? 'badge-muted' ?>"><?= $statusNames[$u->status] ?? '-' ?></span>
            </div>
          </td>
          <td class="bx-td-amount bx-td-amount--pos"><?= number_format((int)($u->wallet_balance ?? 0)) ?></td>
          <td class="bx-td-date"><?= jdate('Y/m/d', strtotime($u->created_at ?? 'now')) ?></td>
          <td class="bx-td-date">
            <?= $u->last_login ? jdate('Y/m/d', strtotime($u->last_login)) : '<span class="bx-td-never">هرگز</span>' ?>
          </td>
          <td>
            <div class="bx-action-group">
              <a href="<?= url('/admin/users/edit/'.$u->id) ?>" class="bx-action-btn bx-action-btn--edit" title="ویرایش">
                <i class="material-icons">edit</i>
              </a>
              <?php if ($u->status !== 'banned'): ?>
              <button class="bx-action-btn bx-action-btn--danger js-user-ban"
                      data-id="<?= (int)$u->id ?>" data-status="<?= e($u->status) ?>"
                      data-url="<?= url('/admin/users/ban/'.$u->id) ?>" title="بن کردن">
                <i class="material-icons">block</i>
              </button>
              <?php else: ?>
              <button class="bx-action-btn bx-action-btn--success js-user-ban"
                      data-id="<?= (int)$u->id ?>" data-status="<?= e($u->status) ?>"
                      data-url="<?= url('/admin/users/ban/'.$u->id) ?>" title="رفع بن">
                <i class="material-icons">lock_open</i>
              </button>
              <?php endif; ?>
              <?php if ($u->status !== 'suspended'): ?>
              <button class="bx-action-btn bx-action-btn--warn js-user-suspend"
                      data-id="<?= (int)$u->id ?>" data-status="<?= e($u->status) ?>"
                      data-url="<?= url('/admin/users/suspend/'.$u->id) ?>" title="تعلیق">
                <i class="material-icons">pause_circle</i>
              </button>
              <?php else: ?>
              <button class="bx-action-btn bx-action-btn--success js-user-suspend"
                      data-id="<?= (int)$u->id ?>" data-status="<?= e($u->status) ?>"
                      data-url="<?= url('/admin/users/suspend/'.$u->id) ?>" title="رفع تعلیق">
                <i class="material-icons">play_circle</i>
              </button>
              <?php endif; ?>
              <button class="bx-action-btn bx-action-btn--danger js-user-delete"
                      data-url="<?= url('/admin/users/delete/'.$u->id) ?>" title="حذف">
                <i class="material-icons">delete</i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (($totalPages ?? 1) > 1): ?>
  <div class="bx-table-footer">
    <div class="bx-pagination">
      <?php if (($currentPage ?? 1) > 1): ?>
      <a class="bx-page-btn" href="?page=<?= ($currentPage-1) ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>"><i class="material-icons">chevron_right</i></a>
      <?php endif; ?>
      <?php $s = max(1, ($currentPage??1)-3); $e = min($totalPages??1, ($currentPage??1)+3); for ($i=$s;$i<=$e;$i++): ?>
      <a class="bx-page-btn <?= $i==($currentPage??1)?'active':'' ?>" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if (($currentPage??1) < ($totalPages??1)): ?>
      <a class="bx-page-btn" href="?page=<?= ($currentPage+1) ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>"><i class="material-icons">chevron_left</i></a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
async function postJson(url, payload={}) {
  const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'}, body:JSON.stringify({...payload,_token:'<?= csrf_token() ?>'}) });
  try { return await res.json(); } catch(e) { throw new Error('پاسخ سرور نامعتبر'); }
}
document.addEventListener('click', async function(e) {
  const banBtn = e.target.closest('.js-user-ban');
  if (banBtn) {
    e.preventDefault();
    const willBan = banBtn.dataset.status !== 'banned';
    const c = await Swal.fire({ title: willBan?'بن کردن کاربر':'رفع بن کاربر', text: willBan?'کاربر مسدود و دسترسی او قطع می‌شود.':'کاربر دوباره فعال خواهد شد.', icon:'warning', showCancelButton:true, confirmButtonText: willBan?'⛔ بن شود':'✅ آزاد شود', cancelButtonText:'انصراف', confirmButtonColor: willBan?'#ef4444':'#10b981' });
    if (!c.isConfirmed) return;
    try { const data = await postJson(banBtn.dataset.url); if(data.success){notyf.success(data.message||'انجام شد');setTimeout(()=>location.reload(),900);}else notyf.error(data.message||'خطا'); } catch(err) { notyf.error(err.message); }
    return;
  }
  const susBtn = e.target.closest('.js-user-suspend');
  if (susBtn) {
    e.preventDefault();
    const willSuspend = susBtn.dataset.status !== 'suspended';
    const c = await Swal.fire({ title: willSuspend?'تعلیق کاربر':'برداشتن تعلیق', text: willSuspend?'کاربر موقتاً محدود می‌شود.':'محدودیت برداشته می‌شود.', icon:'warning', showCancelButton:true, confirmButtonText: willSuspend?'⏸ تعلیق':'▶️ فعال', cancelButtonText:'انصراف', confirmButtonColor:'#f59e0b' });
    if (!c.isConfirmed) return;
    try { const data = await postJson(susBtn.dataset.url); if(data.success){notyf.success(data.message||'انجام شد');setTimeout(()=>location.reload(),900);}else notyf.error(data.message||'خطا'); } catch(err) { notyf.error(err.message); }
    return;
  }
  const delBtn = e.target.closest('.js-user-delete');
  if (delBtn) {
    e.preventDefault();
    const c = await Swal.fire({ title:'حذف کاربر', text:'کاربر به صورت نرم حذف می‌شود.', icon:'warning', showCancelButton:true, confirmButtonText:'🗑 حذف شود', cancelButtonText:'انصراف', confirmButtonColor:'#ef4444' });
    if (!c.isConfirmed) return;
    try { const data = await postJson(delBtn.dataset.url); if(data.success){notyf.success(data.message||'حذف شد');setTimeout(()=>location.reload(),900);}else notyf.error(data.message||'خطا'); } catch(err) { notyf.error(err.message); }
    return;
  }
});
</script>

<?php $content = ob_get_clean(); require_once view_path('layouts.admin'); ?>
