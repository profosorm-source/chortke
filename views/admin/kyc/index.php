<?php
ob_start();
$title  = 'بررسی احراز هویت (KYC)';
$layout = 'admin';
$statusFilter = $statusFilter ?? e($_GET['status'] ?? '', ENT_QUOTES, 'UTF-8');
$searchFilter = $searchFilter ?? e($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8');
$statusColors = ['pending'=>'badge-warning','under_review'=>'badge-info','verified'=>'badge-success','rejected'=>'badge-danger'];
$statusNames  = ['pending'=>'در انتظار','under_review'=>'در بررسی','verified'=>'تأیید شده','rejected'=>'رد شده'];
$docTypes     = ['national_id'=>'کارت ملی','passport'=>'پاسپورت','driving_license'=>'گواهینامه'];
$gradients    = ['linear-gradient(135deg,#5b8af5,#7c3aed)','linear-gradient(135deg,#10b981,#06b6d4)','linear-gradient(135deg,#f59e0b,#ef4444)'];
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--blue"><i class="material-icons">verified_user</i></div>
    <div>
      <h1 class="bx-page-header__title">احراز هویت (KYC)</h1>
      <p class="bx-page-header__sub">بررسی و تأیید مدارک کاربران</p>
    </div>
  </div>
</div>

<div class="bx-stats-row">
  <div class="bx-stat bx-stat--orange">
    <div class="bx-stat__icon"><i class="material-icons">schedule</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($stats['pending'] ?? 0) ?></span><span class="bx-stat__lbl">در انتظار</span></div>
  </div>
  <div class="bx-stat bx-stat--blue">
    <div class="bx-stat__icon"><i class="material-icons">pending</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($stats['under_review'] ?? 0) ?></span><span class="bx-stat__lbl">در حال بررسی</span></div>
  </div>
  <div class="bx-stat bx-stat--green">
    <div class="bx-stat__icon"><i class="material-icons">verified_user</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($stats['verified'] ?? 0) ?></span><span class="bx-stat__lbl">تأیید شده</span></div>
  </div>
  <div class="bx-stat bx-stat--red">
    <div class="bx-stat__icon"><i class="material-icons">cancel</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($stats['rejected'] ?? 0) ?></span><span class="bx-stat__lbl">رد شده</span></div>
  </div>
</div>

<?php if (($stats['pending'] ?? 0) > 0): ?>
<div class="bx-alert bx-alert--orange" style="margin-bottom:20px">
  <i class="material-icons">how_to_reg</i>
  <span><strong><?= $stats['pending'] ?> درخواست KYC</strong> منتظر بررسی شماست.</span>
  <a href="?status=pending" class="btn btn-warning btn-sm" style="margin-right:auto">بررسی کن</a>
</div>
<?php endif; ?>

<form method="GET" action="<?= url('/admin/kyc') ?>">
<div class="bx-filter-bar">
  <div class="bx-filter-bar__fields">
    <div class="bx-filter-bar__search">
      <i class="material-icons">search</i>
      <input type="text" name="search" placeholder="نام کاربر، کد ملی..." value="<?= e($searchFilter) ?>">
    </div>
    <select name="status" class="bx-filter-bar__select">
      <option value="">همه وضعیت‌ها</option>
      <option value="pending"      <?= $statusFilter==='pending'?'selected':'' ?>>در انتظار</option>
      <option value="under_review" <?= $statusFilter==='under_review'?'selected':'' ?>>در حال بررسی</option>
      <option value="verified"     <?= $statusFilter==='verified'?'selected':'' ?>>تأیید شده</option>
      <option value="rejected"     <?= $statusFilter==='rejected'?'selected':'' ?>>رد شده</option>
    </select>
  </div>
  <div class="bx-filter-bar__actions">
    <button type="submit" class="btn btn-primary btn-sm"><i class="material-icons">search</i>جستجو</button>
    <?php if ($statusFilter || !empty($searchFilter)): ?>
    <a href="<?= url('/admin/kyc') ?>" class="btn btn-secondary btn-sm"><i class="material-icons">close</i>پاک</a>
    <?php endif; ?>
  </div>
  <span class="bx-filter-bar__count"><?= number_format(count($verifications ?? [])) ?> مورد</span>
</div>
</form>

<div class="bx-table-card">
  <div class="bx-table-card__header"><h3><i class="material-icons">badge</i>لیست درخواست‌های KYC</h3></div>
  <div class="bx-table-wrap">
    <table class="bx-table">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>نام قانونی</th>
          <th>کد ملی</th>
          <th>نوع مدرک</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($verifications)): ?>
        <tr><td colspan="8"><div class="bx-empty"><i class="material-icons">how_to_reg</i><p>هیچ درخواستی یافت نشد</p></div></td></tr>
      <?php else: ?>
        <?php foreach ($verifications as $v):
          $g = $gradients[$v->user_id % 3];
        ?>
        <tr>
          <td class="bx-td-num">#<?= e($v->id) ?></td>
          <td>
            <div class="bx-user-cell">
              <div class="bx-user-avatar" style="background:<?= $g ?>"><?= mb_substr($v->user_name ?? 'ک', 0, 1, 'UTF-8') ?></div>
              <div class="bx-user-info">
                <strong><?= e($v->user_name ?? '-') ?></strong>
                <small><?= e($v->user_email ?? '') ?></small>
              </div>
            </div>
          </td>
          <td style="font-size:13px;color:var(--fg-primary)"><?= e($v->legal_name ?? '-') ?></td>
          <td><code dir="ltr"><?= e($v->national_code ?? '-') ?></code></td>
          <td><span class="bx-badge badge-muted"><?= $docTypes[$v->document_type ?? ''] ?? '-' ?></span></td>
          <td class="bx-td-date"><?= jdate('Y/m/d H:i', strtotime($v->created_at ?? 'now')) ?></td>
          <td><span class="bx-badge <?= $statusColors[$v->status] ?? 'badge-muted' ?>"><?= $statusNames[$v->status] ?? $v->status ?></span></td>
          <td>
            <div class="bx-action-group">
              <a href="<?= url('/admin/kyc/'.$v->id) ?>" class="bx-action-btn bx-action-btn--view" title="بررسی مدارک">
                <i class="material-icons">visibility</i>
              </a>
              <?php if (in_array($v->status, ['pending','under_review'])): ?>
              <button class="bx-action-btn bx-action-btn--success js-kyc-approve"
                      data-url="<?= url('/admin/kyc/'.$v->id.'/approve') ?>" title="تأیید">
                <i class="material-icons">check_circle</i>
              </button>
              <button class="bx-action-btn bx-action-btn--danger js-kyc-reject"
                      data-url="<?= url('/admin/kyc/'.$v->id.'/reject') ?>" title="رد">
                <i class="material-icons">cancel</i>
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
  <?php if (($totalPages ?? 1) > 1): ?>
  <div class="bx-table-footer">
    <div class="bx-pagination">
      <?php for ($i=1;$i<=min($totalPages,10);$i++): ?>
      <a class="bx-page-btn <?= $i==($currentPage??1)?'active':'' ?>" href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
async function postJson(url, payload={}) {
  const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'}, body:JSON.stringify({...payload,_token:'<?= csrf_token() ?>'}) });
  try { return await res.json(); } catch(e) { throw new Error('پاسخ نامعتبر'); }
}
document.addEventListener('click', async function(e) {
  const approveBtn = e.target.closest('.js-kyc-approve');
  if (approveBtn) {
    e.preventDefault();
    const c = await Swal.fire({title:'تأیید KYC',text:'مدارک تأیید شده و وضعیت KYC به «تأیید شده» تغییر می‌کند.',icon:'question',showCancelButton:true,confirmButtonText:'✅ تأیید',cancelButtonText:'انصراف',confirmButtonColor:'#10b981'});
    if (!c.isConfirmed) return;
    try { const d = await postJson(approveBtn.dataset.url); if(d.success){notyf.success(d.message||'تأیید شد');setTimeout(()=>location.reload(),900);}else notyf.error(d.message||'خطا'); } catch(err) { notyf.error(err.message); }
    return;
  }
  const rejectBtn = e.target.closest('.js-kyc-reject');
  if (rejectBtn) {
    e.preventDefault();
    const {value:reason,isConfirmed} = await Swal.fire({title:'رد KYC',input:'textarea',inputLabel:'دلیل رد:',inputPlaceholder:'مثلاً: تصویر واضح نیست...',icon:'warning',showCancelButton:true,confirmButtonText:'⛔ رد',cancelButtonText:'انصراف',confirmButtonColor:'#ef4444',inputValidator:val=>!val?'دلیل رد الزامی است':null});
    if (!isConfirmed) return;
    try { const d = await postJson(rejectBtn.dataset.url,{reason}); if(d.success){notyf.success(d.message||'رد شد');setTimeout(()=>location.reload(),900);}else notyf.error(d.message||'خطا'); } catch(err) { notyf.error(err.message); }
    return;
  }
});
</script>

<?php $content = ob_get_clean(); require_once view_path('layouts.admin'); ?>
