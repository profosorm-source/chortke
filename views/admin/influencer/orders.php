<?php $title='سفارش‌های اینفلوئنسر'; $layout='admin'; ob_start();
$badgeMap = [
    'badge-success'  => 'bg-success',
    'badge-primary'  => 'bg-primary',
    'badge-warning'  => 'bg-warning text-dark',
    'badge-info'     => 'bg-info text-dark',
    'badge-danger'   => 'bg-danger',
    'badge-secondary'=> 'bg-secondary',
    'badge-orange'   => 'bg-warning',
];
?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-primary">campaign</i> سفارش‌های اینفلوئنسر
  </h4>
  <a href="<?= url('/admin/influencer/profiles') ?>" class="btn btn-outline-primary btn-sm">
    <i class="material-icons" style="font-size:15px;vertical-align:middle;">groups</i>
    پروفایل‌ها
  </a>
</div>

<!-- آمار -->
<?php if(!empty($stats)): ?>
<div class="row mt-3 g-2">
  <div class="col-6 col-md-2">
    <div class="card text-center"><div class="card-body py-2">
      <div class="fs-5 fw-bold"><?= number_format($stats->total_orders ?? 0) ?></div>
      <div class="small text-muted">کل سفارش</div>
    </div></div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center"><div class="card-body py-2">
      <div class="fs-5 fw-bold text-success"><?= number_format($stats->completed_orders ?? 0) ?></div>
      <div class="small text-muted">تکمیل‌شده</div>
    </div></div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center"><div class="card-body py-2">
      <div class="fs-5 fw-bold text-primary"><?= number_format($stats->active_orders ?? 0) ?></div>
      <div class="small text-muted">فعال</div>
    </div></div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center <?= ($stats->pending_buyer_check ?? 0) > 0 ? 'border-warning' : '' ?>">
      <div class="card-body py-2">
        <div class="fs-5 fw-bold text-warning"><?= number_format($stats->pending_buyer_check ?? 0) ?></div>
        <div class="small text-muted">در انتظار تایید</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center <?= ($stats->in_dispute ?? 0) > 0 ? 'border-danger' : '' ?>">
      <div class="card-body py-2">
        <div class="fs-5 fw-bold text-danger"><?= number_format($stats->in_dispute ?? 0) ?></div>
        <div class="small text-muted">اختلاف</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center"><div class="card-body py-2">
      <div class="fs-5 fw-bold text-success"><?= number_format($stats->total_site_earning ?? 0) ?></div>
      <div class="small text-muted">درآمد سایت</div>
    </div></div>
  </div>
</div>
<?php endif; ?>

<!-- فیلتر -->
<div class="card mt-3">
  <div class="card-body py-2">
    <form method="GET" action="<?= url('/admin/influencer/orders') ?>">
      <div class="row g-2">
        <div class="col-md-3">
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="جستجو (یوزرنیم/نام)" value="<?= e($filters['search'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <select name="status" class="form-select form-select-sm">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach(($statusLabels ?? []) as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>>
                <?= e($v) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <select name="order_type" class="form-select form-select-sm">
            <option value="">همه انواع</option>
            <option value="story" <?= ($filters['order_type'] ?? '') === 'story' ? 'selected' : '' ?>>استوری</option>
            <option value="post"  <?= ($filters['order_type'] ?? '') === 'post'  ? 'selected' : '' ?>>پست</option>
          </select>
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary btn-sm w-100">فیلتر</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- جدول -->
<div class="card mt-3">
  <div class="card-header d-flex justify-content-between">
    <h6 class="card-title mb-0">لیست سفارش‌ها</h6>
    <span class="badge bg-info"><?= number_format($total ?? 0) ?> رکورد</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:12px;">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>اینفلوئنسر</th>
            <th>تبلیغ‌دهنده</th>
            <th>نوع</th>
            <th>مبلغ</th>
            <th>مدرک</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($orders)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">سفارشی یافت نشد.</td></tr>
          <?php else: ?>
          <?php foreach($orders as $o):
            $sc  = $statusClasses ?? [];
            $sl  = $statusLabels  ?? [];
            $cls = $badgeMap[$sc[$o->status] ?? 'badge-secondary'] ?? 'bg-secondary';
            $inDispute = \in_array($o->status, ['peer_resolution','escalated_to_admin']);
          ?>
          <tr class="<?= $inDispute ? 'table-danger' : ($o->status === 'awaiting_buyer_check' ? 'table-warning' : '') ?>">
            <td><?= e($o->id) ?></td>
            <td><strong>@<?= e($o->influencer_username ?? '—') ?></strong></td>
            <td><?= e($o->customer_name ?? '—') ?></td>
            <td><?= $o->order_type === 'story' ? 'استوری' : 'پست' ?> / <?= $o->duration_hours ?? 24 ?>h</td>
            <td class="text-success fw-bold"><?= number_format($o->price ?? 0) ?></td>
            <td>
              <?php if(!empty($o->proof_link)): ?>
                <a href="<?= e($o->proof_link) ?>" target="_blank" class="btn btn-outline-info btn-sm py-0">
                  <i class="material-icons" style="font-size:13px;vertical-align:middle;">link</i>
                </a>
              <?php elseif(!empty($o->proof_screenshot)): ?>
                <a href="<?= e($o->proof_screenshot) ?>" target="_blank" class="btn btn-outline-info btn-sm py-0">
                  <i class="material-icons" style="font-size:13px;vertical-align:middle;">image</i>
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $cls ?>"><?= e($sl[$o->status] ?? $o->status) ?></span></td>
            <td><?= e(substr($o->created_at ?? '', 0, 10)) ?></td>
            <td>
              <?php if($inDispute): ?>
                <a href="<?= url('/admin/influencer/disputes') ?>" class="btn btn-danger btn-sm py-0">
                  داوری
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if(($pages??1) > 1): ?>
  <div class="card-footer">
    <nav><ul class="pagination pagination-sm mb-0">
      <?php for($i=1;$i<=($pages??1);$i++): ?>
        <li class="page-item <?= $i===($page??1)?'active':'' ?>">
          <a class="page-link"
             href="?page=<?= $i ?>&status=<?= e($filters['status']??'') ?>&order_type=<?= e($filters['order_type']??'') ?>&search=<?= e($filters['search']??'') ?>">
            <?= $i ?>
          </a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<?php $content=ob_get_clean(); include view_path('layouts.'.$layout); ?>
