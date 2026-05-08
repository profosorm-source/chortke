<?php
ob_start();
$title = 'مدیریت برداشت‌ها';
$layout = 'admin';
$statusFilter   = $status   ?? '';
$currencyFilter = $currency ?? '';
$stMap  = ['pending'=>'badge-warning','processing'=>'badge-info','completed'=>'badge-success','rejected'=>'badge-danger'];
$stLbl  = ['pending'=>'در انتظار','processing'=>'پردازش','completed'=>'تکمیل','rejected'=>'رد شده'];
$gradients = ['linear-gradient(135deg,#5b8af5,#7c3aed)','linear-gradient(135deg,#10b981,#06b6d4)','linear-gradient(135deg,#f59e0b,#ef4444)'];
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--orange"><i class="material-icons">account_balance_wallet</i></div>
    <div>
      <h1 class="bx-page-header__title">مدیریت برداشت‌ها</h1>
      <p class="bx-page-header__sub">مجموع <strong><?= number_format($total ?? 0) ?></strong> درخواست</p>
    </div>
  </div>
</div>

<?php if (($summary['pending'] ?? 0) > 0): ?>
<div class="bx-alert bx-alert--orange" style="margin-bottom:20px">
  <i class="material-icons">warning_amber</i>
  <strong><?= $summary['pending'] ?> درخواست برداشت</strong>&nbsp;در انتظار تأیید شما است.
  <a href="?status=pending" class="btn btn-warning btn-sm" style="margin-right:auto">مشاهده</a>
</div>
<?php endif; ?>

<div class="bx-stats-row">
  <div class="bx-stat bx-stat--orange">
    <div class="bx-stat__icon"><i class="material-icons">schedule</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($summary['pending'] ?? 0) ?></span><span class="bx-stat__lbl">در انتظار</span></div>
  </div>
  <div class="bx-stat bx-stat--blue">
    <div class="bx-stat__icon"><i class="material-icons">autorenew</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($summary['processing'] ?? 0) ?></span><span class="bx-stat__lbl">پردازش</span></div>
  </div>
  <div class="bx-stat bx-stat--green">
    <div class="bx-stat__icon"><i class="material-icons">check_circle</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($summary['completed'] ?? 0) ?></span><span class="bx-stat__lbl">تکمیل شده</span></div>
  </div>
  <div class="bx-stat bx-stat--gold">
    <div class="bx-stat__icon"><i class="material-icons">payments</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($summary['total_amount'] ?? 0) ?></span><span class="bx-stat__lbl">مجموع پرداخت (تومان)</span></div>
  </div>
</div>

<form method="GET" action="<?= url('/admin/withdrawals') ?>">
<div class="bx-filter-bar">
  <div class="bx-filter-bar__fields">
    <select name="status" class="bx-filter-bar__select">
      <option value="">همه وضعیت‌ها</option>
      <option value="pending"    <?= $statusFilter==='pending'?'selected':'' ?>>در انتظار</option>
      <option value="processing" <?= $statusFilter==='processing'?'selected':'' ?>>در حال پردازش</option>
      <option value="completed"  <?= $statusFilter==='completed'?'selected':'' ?>>تکمیل شده</option>
      <option value="rejected"   <?= $statusFilter==='rejected'?'selected':'' ?>>رد شده</option>
    </select>
    <select name="currency" class="bx-filter-bar__select">
      <option value="">همه ارزها</option>
      <option value="irt"  <?= $currencyFilter==='irt'?'selected':'' ?>>تومان (IRT)</option>
      <option value="usdt" <?= $currencyFilter==='usdt'?'selected':'' ?>>تتر (USDT)</option>
    </select>
  </div>
  <div class="bx-filter-bar__actions">
    <button type="submit" class="btn btn-primary btn-sm"><i class="material-icons">filter_list</i>فیلتر</button>
    <?php if ($statusFilter || $currencyFilter): ?>
    <a href="<?= url('/admin/withdrawals') ?>" class="btn btn-secondary btn-sm"><i class="material-icons">close</i>پاک</a>
    <?php endif; ?>
  </div>
</div>
</form>

<div class="bx-table-card">
  <div class="bx-table-card__header">
    <h3><i class="material-icons">list</i>لیست درخواست‌ها</h3>
  </div>
  <div class="bx-table-wrap">
    <table class="bx-table">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>مبلغ</th>
          <th>ارز</th>
          <th>روش</th>
          <th>وضعیت</th>
          <th>تاریخ</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($withdrawals)): ?>
        <tr><td colspan="8"><div class="bx-empty"><i class="material-icons">account_balance_wallet</i><p>هیچ درخواستی یافت نشد</p></div></td></tr>
      <?php else: ?>
        <?php foreach ($withdrawals as $w):
          $g = $gradients[$w->user_id % 3];
        ?>
        <tr>
          <td><code>#<?= e($w->id) ?></code></td>
          <td>
            <div class="bx-user-cell">
              <div class="bx-user-avatar" style="background:<?= $g ?>;width:28px;height:28px;font-size:11px"><?= mb_substr($w->full_name ?? 'ک', 0, 1, 'UTF-8') ?></div>
              <div class="bx-user-info">
                <strong><?= e($w->full_name ?? '-') ?></strong>
                <small><?= e($w->user_email ?? '') ?></small>
              </div>
            </div>
          </td>
          <td class="bx-td-amount bx-td-amount--neg"><?= number_format((float)($w->amount ?? 0)) ?></td>
          <td><span class="bx-badge <?= strtolower($w->currency??'')==='usdt'?'badge-info':'badge-primary' ?>"><?= strtoupper($w->currency ?? 'IRT') ?></span></td>
          <td style="font-size:12px;color:var(--fg-muted)"><?= e($w->method ?? '—') ?></td>
          <td>
            <div class="bx-status-cell">
              <span class="bx-status-dot bx-status-dot--<?= $w->status==='completed'?'active':($w->status==='rejected'?'banned':'suspended') ?>"></span>
              <span class="bx-badge <?= $stMap[$w->status] ?? 'badge-muted' ?>"><?= $stLbl[$w->status] ?? $w->status ?></span>
            </div>
          </td>
          <td class="bx-td-date"><?= jdate('Y/m/d H:i', strtotime($w->created_at ?? 'now')) ?></td>
          <td>
            <div class="bx-action-group">
              <a href="<?= url('/admin/withdrawals/'.$w->id) ?>" class="bx-action-btn bx-action-btn--view" title="بررسی">
                <i class="material-icons">visibility</i>
              </a>
              <?php if ($w->status === 'pending'): ?>
              <button class="bx-action-btn bx-action-btn--success js-w-approve" data-id="<?= e($w->id) ?>" title="تأیید سریع">
                <i class="material-icons">check</i>
              </button>
              <button class="bx-action-btn bx-action-btn--danger js-w-reject" data-id="<?= e($w->id) ?>" title="رد">
                <i class="material-icons">close</i>
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
      <a class="bx-page-btn <?= $i==($currentPage??1)?'active':'' ?>" href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&currency=<?= urlencode($currencyFilter) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
const csrf = '<?= csrf_token() ?>';
document.querySelectorAll('.js-w-approve').forEach(btn => {
  btn.addEventListener('click', function() {
    const id = this.dataset.id;
    Swal.fire({title:'تأیید برداشت',html:'<input type="text" id="swal-track" class="swal2-input" placeholder="شماره پیگیری...">',icon:'question',showCancelButton:true,confirmButtonText:'✅ تأیید',cancelButtonText:'انصراف',confirmButtonColor:'#0ECB81',preConfirm:()=>{const v=document.getElementById('swal-track').value.trim();if(!v){Swal.showValidationMessage('شماره پیگیری الزامی است');}return v;}})
    .then(r=>{if(!r.isConfirmed)return;fetch(`<?= url('/admin/withdrawals') ?>/${id}/approve`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({tracking_code:r.value})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),900);}else{notyf.error(d.message);}});});
  });
});
document.querySelectorAll('.js-w-reject').forEach(btn => {
  btn.addEventListener('click', function() {
    const id = this.dataset.id;
    Swal.fire({title:'رد درخواست برداشت',input:'textarea',inputPlaceholder:'دلیل رد...',icon:'warning',showCancelButton:true,confirmButtonText:'⛔ رد',cancelButtonText:'انصراف',confirmButtonColor:'#F6465D'})
    .then(r=>{if(!r.isConfirmed)return;fetch(`<?= url('/admin/withdrawals') ?>/${id}/reject`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({reason:r.value})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),900);}else{notyf.error(d.message);}});});
  });
});
</script>

<?php $content = ob_get_clean(); require_once view_path('layouts.admin'); ?>
