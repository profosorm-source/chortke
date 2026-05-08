<?php $title='اختلاف‌های اینفلوئنسر'; $layout='admin'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-warning">gavel</i> اختلاف‌ها
  </h4>
  <a href="<?= url('/admin/influencer/orders') ?>" class="btn btn-outline-secondary btn-sm">بازگشت</a>
</div>

<div class="card mt-3">
  <div class="card-body py-2">
    <form method="GET">
      <div class="row g-2">
        <div class="col-md-4">
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <select name="status" class="form-select form-select-sm">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach($statusLabels as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>>
                <?= e($v) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary btn-sm w-100">فیلتر</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header d-flex justify-content-between">
    <h6 class="card-title mb-0">لیست اختلاف‌ها</h6>
    <span class="badge bg-info"><?= number_format($total ?? 0) ?> مورد</span>
  </div>
  <div class="card-body p-0">
    <?php if(empty($disputes)): ?>
      <div class="text-center py-4 text-muted small">اختلافی یافت نشد.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead class="table-light">
          <tr>
            <th>#</th><th>اینفلوئنسر</th><th>تبلیغ‌دهنده</th>
            <th>مبلغ</th><th>وضعیت</th><th>تاریخ</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($disputes as $d):
            $needsAction = $d->status === 'escalated';
          ?>
          <tr class="<?= $needsAction ? 'table-warning' : '' ?>">
            <td><?= e($d->id) ?></td>
            <td>@<?= e($d->influencer_username ?? '—') ?></td>
            <td><?= e($d->customer_name ?? '—') ?></td>
            <td class="text-success fw-bold"><?= number_format($d->price ?? 0) ?></td>
            <td>
              <span class="badge bg-<?= $needsAction ? 'danger' : 'secondary' ?>">
                <?= e($statusLabels[$d->status] ?? $d->status) ?>
              </span>
            </td>
            <td><?= e(substr($d->created_at ?? '', 0, 10)) ?></td>
            <td>
              <a href="<?= url('/admin/influencer/disputes/' . (int)$d->id) ?>"
                 class="btn btn-<?= $needsAction ? 'danger' : 'outline-secondary' ?> btn-sm">
                <?= $needsAction ? 'داوری' : 'مشاهده' ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php if(($pages??1) > 1): ?>
  <div class="card-footer">
    <nav><ul class="pagination pagination-sm mb-0">
      <?php for($i=1;$i<=$pages;$i++): ?>
        <li class="page-item <?= $i===$page?'active':'' ?>">
          <a class="page-link" href="?page=<?= $i ?>&status=<?= e($filters['status']??'') ?>&search=<?= e($filters['search']??'') ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<?php $content=ob_get_clean(); include view_path('layouts.'.$layout); ?>
