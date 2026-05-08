<?php
ob_start();
$title = 'تراکنش‌های مالی';
$layout = 'admin';
$statusFilter   = $status   ?? '';
$typeFilter     = $type     ?? '';
$currencyFilter = $currency ?? '';
$statusColors = ['pending'=>'badge-warning','processing'=>'badge-info','completed'=>'badge-success','failed'=>'badge-danger','cancelled'=>'badge-muted'];
$statusNames  = ['pending'=>'در انتظار','processing'=>'پردازش','completed'=>'تکمیل','failed'=>'ناموفق','cancelled'=>'لغو'];
$typeColors   = ['deposit'=>'badge-success','withdraw'=>'badge-danger','transfer'=>'badge-info','commission'=>'badge-primary','task_reward'=>'badge-purple','penalty'=>'badge-danger'];
$typeNames    = ['deposit'=>'واریز','withdraw'=>'برداشت','transfer'=>'انتقال','commission'=>'کمیسیون','task_reward'=>'پاداش','penalty'=>'جریمه'];
$gradients    = ['linear-gradient(135deg,#5b8af5,#7c3aed)','linear-gradient(135deg,#10b981,#06b6d4)','linear-gradient(135deg,#f59e0b,#ef4444)'];
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon"><i class="material-icons">receipt_long</i></div>
    <div>
      <h1 class="bx-page-header__title">تراکنش‌های مالی</h1>
      <p class="bx-page-header__sub">مجموع <strong><?= number_format($total ?? 0) ?></strong> تراکنش</p>
    </div>
  </div>
  <a href="<?= url('/admin/export?type=transactions') ?>" class="btn btn-secondary btn-sm">
    <i class="material-icons">file_download</i>خروجی Excel
  </a>
</div>

<form method="GET" action="<?= url('/admin/transactions') ?>">
<div class="bx-filter-bar">
  <div class="bx-filter-bar__fields">
    <select name="type" class="bx-filter-bar__select">
      <option value="">همه انواع</option>
      <option value="deposit"     <?= $typeFilter==='deposit'?'selected':'' ?>>واریز</option>
      <option value="withdraw"    <?= $typeFilter==='withdraw'?'selected':'' ?>>برداشت</option>
      <option value="transfer"    <?= $typeFilter==='transfer'?'selected':'' ?>>انتقال</option>
      <option value="commission"  <?= $typeFilter==='commission'?'selected':'' ?>>کمیسیون</option>
      <option value="task_reward" <?= $typeFilter==='task_reward'?'selected':'' ?>>پاداش تسک</option>
      <option value="penalty"     <?= $typeFilter==='penalty'?'selected':'' ?>>جریمه</option>
    </select>
    <select name="status" class="bx-filter-bar__select">
      <option value="">همه وضعیت‌ها</option>
      <option value="pending"    <?= $statusFilter==='pending'?'selected':'' ?>>در انتظار</option>
      <option value="processing" <?= $statusFilter==='processing'?'selected':'' ?>>پردازش</option>
      <option value="completed"  <?= $statusFilter==='completed'?'selected':'' ?>>تکمیل</option>
      <option value="failed"     <?= $statusFilter==='failed'?'selected':'' ?>>ناموفق</option>
      <option value="cancelled"  <?= $statusFilter==='cancelled'?'selected':'' ?>>لغو</option>
    </select>
    <select name="currency" class="bx-filter-bar__select">
      <option value="">همه ارزها</option>
      <option value="irt"  <?= $currencyFilter==='irt'?'selected':'' ?>>تومان</option>
      <option value="usdt" <?= $currencyFilter==='usdt'?'selected':'' ?>>USDT</option>
    </select>
  </div>
  <div class="bx-filter-bar__actions">
    <button type="submit" class="btn btn-primary btn-sm"><i class="material-icons">filter_list</i>فیلتر</button>
    <?php if ($statusFilter || $typeFilter || $currencyFilter): ?>
    <a href="<?= url('/admin/transactions') ?>" class="btn btn-secondary btn-sm"><i class="material-icons">close</i>پاک</a>
    <?php endif; ?>
  </div>
</div>
</form>

<div class="bx-table-card">
  <div class="bx-table-card__header">
    <h3><i class="material-icons">receipt_long</i>لیست تراکنش‌ها</h3>
  </div>
  <div class="bx-table-wrap">
    <table class="bx-table">
      <thead>
        <tr>
          <th>شناسه</th>
          <th>کاربر</th>
          <th>نوع</th>
          <th>مبلغ</th>
          <th>ارز</th>
          <th>وضعیت</th>
          <th>تاریخ</th>
          <th>توضیح</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($transactions)): ?>
        <tr><td colspan="8"><div class="bx-empty"><i class="material-icons">receipt_long</i><p>هیچ تراکنشی یافت نشد</p></div></td></tr>
      <?php else: ?>
        <?php foreach ($transactions as $tx):
          $g = $gradients[$tx->user_id % 3];
          $isPositive = in_array($tx->type, ['deposit','task_reward','commission']);
        ?>
        <tr>
          <td><code style="font-size:10px"><?= e(substr($tx->transaction_id ?? '-', 0, 10)) ?>…</code></td>
          <td>
            <div class="bx-user-cell">
              <div class="bx-user-avatar" style="background:<?= $g ?>;width:28px;height:28px;font-size:11px"><?= mb_substr($tx->full_name ?? 'ک', 0, 1, 'UTF-8') ?></div>
              <span style="font-size:12px;color:var(--fg-primary);font-weight:600"><?= e($tx->full_name ?? '-') ?></span>
            </div>
          </td>
          <td><span class="bx-badge <?= $typeColors[$tx->type] ?? 'badge-muted' ?>"><?= $typeNames[$tx->type] ?? $tx->type ?></span></td>
          <td class="bx-td-amount <?= $isPositive?'bx-td-amount--pos':'bx-td-amount--neg' ?>">
            <?= $isPositive?'+':'-' ?><?= number_format((float)($tx->amount ?? 0)) ?>
          </td>
          <td><span class="bx-badge <?= strtolower($tx->currency??'')==='usdt'?'badge-info':'badge-primary' ?>"><?= strtoupper($tx->currency ?? 'IRT') ?></span></td>
          <td><span class="bx-badge <?= $statusColors[$tx->status] ?? 'badge-muted' ?>"><?= $statusNames[$tx->status] ?? $tx->status ?></span></td>
          <td class="bx-td-date"><?= jdate('Y/m/d H:i', strtotime($tx->created_at ?? 'now')) ?></td>
          <td style="font-size:12px;color:var(--fg-muted);max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e(mb_substr($tx->description ?? '-', 0, 30, 'UTF-8')) ?></td>
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
      <a class="bx-page-btn <?= $i==($currentPage??1)?'active':'' ?>" href="?page=<?= $i ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>&currency=<?= urlencode($currencyFilter) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php $content = ob_get_clean(); require_once view_path('layouts.admin'); ?>
