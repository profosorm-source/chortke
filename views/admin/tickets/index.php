<?php
ob_start();
$title = 'مدیریت تیکت‌ها';
$layout = 'admin';
$statusFilter   = $status   ?? '';
$priorityFilter = $priority ?? '';
$stMap  = ['open'=>'badge-success','pending'=>'badge-warning','in_progress'=>'badge-info','closed'=>'badge-muted','resolved'=>'badge-success'];
$stLbl  = ['open'=>'باز','pending'=>'در انتظار','in_progress'=>'در جریان','closed'=>'بسته','resolved'=>'حل شده'];
$prMap  = ['low'=>'badge-muted','normal'=>'badge-info','high'=>'badge-warning','urgent'=>'badge-danger'];
$prLbl  = ['low'=>'کم','normal'=>'معمولی','high'=>'زیاد','urgent'=>'فوری'];
$gradients = ['linear-gradient(135deg,#5b8af5,#7c3aed)','linear-gradient(135deg,#10b981,#06b6d4)','linear-gradient(135deg,#f59e0b,#ef4444)'];
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--blue"><i class="material-icons">support_agent</i></div>
    <div>
      <h1 class="bx-page-header__title">مدیریت تیکت‌ها</h1>
      <p class="bx-page-header__sub">مجموع <strong><?= number_format($total ?? 0) ?></strong> تیکت</p>
    </div>
  </div>
</div>

<div class="bx-stats-row">
  <div class="bx-stat bx-stat--orange">
    <div class="bx-stat__icon"><i class="material-icons">inbox</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($stats['open'] ?? 0) ?></span><span class="bx-stat__lbl">باز</span></div>
  </div>
  <div class="bx-stat bx-stat--gold">
    <div class="bx-stat__icon"><i class="material-icons">pending</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($stats['pending'] ?? 0) ?></span><span class="bx-stat__lbl">در انتظار</span></div>
  </div>
  <div class="bx-stat bx-stat--blue">
    <div class="bx-stat__icon"><i class="material-icons">sync</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($stats['in_progress'] ?? 0) ?></span><span class="bx-stat__lbl">در جریان</span></div>
  </div>
  <div class="bx-stat bx-stat--green">
    <div class="bx-stat__icon"><i class="material-icons">check_circle</i></div>
    <div class="bx-stat__body"><span class="bx-stat__num"><?= number_format($stats['resolved'] ?? 0) ?></span><span class="bx-stat__lbl">حل شده</span></div>
  </div>
</div>

<form method="GET" action="<?= url('/admin/tickets') ?>">
<div class="bx-filter-bar">
  <div class="bx-filter-bar__fields">
    <select name="status" class="bx-filter-bar__select">
      <option value="">همه وضعیت‌ها</option>
      <option value="open"        <?= $statusFilter==='open'?'selected':'' ?>>باز</option>
      <option value="pending"     <?= $statusFilter==='pending'?'selected':'' ?>>در انتظار</option>
      <option value="in_progress" <?= $statusFilter==='in_progress'?'selected':'' ?>>در جریان</option>
      <option value="closed"      <?= $statusFilter==='closed'?'selected':'' ?>>بسته</option>
      <option value="resolved"    <?= $statusFilter==='resolved'?'selected':'' ?>>حل شده</option>
    </select>
    <select name="priority" class="bx-filter-bar__select">
      <option value="">همه اولویت‌ها</option>
      <option value="low"    <?= $priorityFilter==='low'?'selected':'' ?>>کم</option>
      <option value="normal" <?= $priorityFilter==='normal'?'selected':'' ?>>معمولی</option>
      <option value="high"   <?= $priorityFilter==='high'?'selected':'' ?>>زیاد</option>
      <option value="urgent" <?= $priorityFilter==='urgent'?'selected':'' ?>>فوری</option>
    </select>
  </div>
  <div class="bx-filter-bar__actions">
    <button type="submit" class="btn btn-primary btn-sm"><i class="material-icons">filter_list</i>فیلتر</button>
    <?php if ($statusFilter || $priorityFilter): ?>
    <a href="<?= url('/admin/tickets') ?>" class="btn btn-secondary btn-sm"><i class="material-icons">close</i>پاک</a>
    <?php endif; ?>
  </div>
</div>
</form>

<div class="bx-table-card">
  <div class="bx-table-card__header"><h3><i class="material-icons">list</i>لیست تیکت‌ها</h3></div>
  <div class="bx-table-wrap">
    <table class="bx-table">
      <thead>
        <tr>
          <th>#</th>
          <th>موضوع</th>
          <th>کاربر</th>
          <th>دسته</th>
          <th>اولویت</th>
          <th>وضعیت</th>
          <th>آخرین پیام</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($tickets)): ?>
        <tr><td colspan="8"><div class="bx-empty"><i class="material-icons">support_agent</i><p>هیچ تیکتی یافت نشد</p></div></td></tr>
      <?php else: ?>
        <?php foreach ($tickets as $t):
          $g = $gradients[$t->user_id % 3];
          $isUrgent = ($t->priority ?? '') === 'urgent';
        ?>
        <tr <?= $isUrgent ? 'style="background:rgba(246,70,93,.03);"' : '' ?>>
          <td><code>#<?= e($t->id) ?></code></td>
          <td>
            <div style="max-width:200px">
              <a href="<?= url('/admin/tickets/'.$t->id) ?>" style="font-size:13px;font-weight:600;color:var(--fg-primary);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= e($t->subject ?? '') ?>">
                <?= e($t->subject ?? '—') ?>
              </a>
              <?php if (!empty($t->last_message_preview)): ?>
              <small style="color:var(--fg-muted);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block"><?= e(mb_substr($t->last_message_preview, 0, 40, 'UTF-8')) ?>…</small>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <div class="bx-user-cell">
              <div class="bx-user-avatar" style="background:<?= $g ?>;width:26px;height:26px;font-size:10px"><?= mb_substr($t->user_name ?? 'ک', 0, 1, 'UTF-8') ?></div>
              <span style="font-size:12px;color:var(--fg-primary)"><?= e($t->user_name ?? '-') ?></span>
            </div>
          </td>
          <td style="font-size:11px;color:var(--fg-muted)"><?= e($t->category_name ?? '—') ?></td>
          <td><span class="bx-badge <?= $prMap[$t->priority ?? 'normal'] ?? 'badge-muted' ?>"><?= $prLbl[$t->priority ?? 'normal'] ?? '—' ?></span></td>
          <td><span class="bx-badge <?= $stMap[$t->status ?? ''] ?? 'badge-muted' ?>"><?= $stLbl[$t->status ?? ''] ?? $t->status ?></span></td>
          <td class="bx-td-date"><?= to_jalali($t->updated_at ?? $t->created_at ?? '') ?></td>
          <td>
            <div class="bx-action-group">
              <a href="<?= url('/admin/tickets/'.$t->id) ?>" class="bx-action-btn bx-action-btn--view" title="مشاهده و پاسخ">
                <i class="material-icons">open_in_new</i>
              </a>
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
      <a class="bx-page-btn <?= $i==($currentPage??1)?'active':'' ?>" href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php $content = ob_get_clean(); require_once view_path('layouts.admin'); ?>
