<?php $title='پروفایل‌های اینفلوئنسر'; $layout='admin'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-primary">groups</i> پروفایل‌های اینفلوئنسر
  </h4>
  <div class="d-flex gap-2">
    <a href="<?= url('/admin/influencer/verifications') ?>" class="btn btn-info btn-sm">
      <i class="material-icons" style="font-size:15px;vertical-align:middle;">check_circle</i> درخواست‌های تایید
    </a>
    <a href="<?= url('/admin/influencer/orders') ?>" class="btn btn-outline-secondary btn-sm">
      <i class="material-icons" style="font-size:15px;vertical-align:middle;">arrow_forward</i> بازگشت
    </a>
  </div>
  <div class="card-body py-2">
    <form method="GET" action="<?= url('/admin/influencer/profiles') ?>">
      <div class="row g-2">
        <div class="col-md-4">
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="جستجو (یوزرنیم/نام)" value="<?= e($filters['search'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <select name="status" class="form-select form-select-sm">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach($statusLabels as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= ($filters['status']??'') === $k ? 'selected':'' ?>><?= e($v) ?></option>
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

<!-- جدول -->
<div class="card mt-3">
  <div class="card-header d-flex justify-content-between">
    <h6 class="card-title mb-0">لیست پیج‌ها</h6>
    <span class="badge bg-info"><?= number_format($total ?? 0) ?> رکورد</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:12px;">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th style="min-width:180px;">پیج</th>
            <th>کاربر</th>
            <th>فالوور</th>
            <th>تعرفه استوری</th>
            <th>وضعیت</th>
            <th>مدرک تایید</th>
            <th>تاریخ</th>
            <th style="min-width:150px;">عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($profiles)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">رکوردی یافت نشد.</td></tr>
          <?php else: ?>
          <?php foreach($profiles as $idx => $p):
            $stMap = [
              'pending'              => ['در انتظار کد','badge-secondary'],
              'pending_admin_review' => ['در انتظار تایید','badge-warning'],
              'verified'             => ['تایید شده','badge-success'],
              'rejected'             => ['رد شده','badge-danger'],
              'suspended'            => ['تعلیق','badge-dark'],
            ];
            $st = $stMap[$p->status] ?? [$p->status,'badge-secondary'];
            $needsReview = $p->status === 'pending_admin_review';
          ?>
          <tr class="<?= $needsReview ? 'table-warning' : '' ?>">
            <td class="text-muted"><?= (((int)($page??1)-1)*30) + $idx + 1 ?></td>
            <!-- پیج -->
            <td>
              <div class="d-flex align-items-center gap-2">
                <?php if(!empty($p->profile_image)): ?>
                  <img src="<?= e($p->profile_image) ?>" class="rounded-circle"
                       style="width:34px;height:34px;object-fit:cover;">
                <?php else: ?>
                  <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white"
                       style="width:34px;height:34px;font-size:13px;font-weight:bold;">
                    <?= mb_strtoupper(mb_substr($p->username ?? 'U', 0, 1)) ?>
                  </div>
                <?php endif; ?>
                <div>
                  <div class="fw-bold">@<?= e($p->username) ?></div>
                  <a href="<?= e($p->page_url ?? '#') ?>" target="_blank" class="text-muted"
                     style="font-size:10px;">مشاهده پیج</a>
                </div>
              </div>
            </td>
            <td><?= e($p->full_name ?? '—') ?></td>
            <td><?= number_format($p->follower_count ?? 0) ?></td>
            <td class="text-success fw-bold">
              <?= $p->story_price_24h > 0 ? number_format($p->story_price_24h) : '—' ?>
            </td>
            <!-- وضعیت -->
            <td><span class="badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span></td>
            <!-- مدرک تایید -->
            <td>
              <?php if(!empty($p->verification_code)): ?>
                <code style="font-size:10px;"><?= e($p->verification_code) ?></code>
              <?php endif; ?>
              <?php if(!empty($p->verification_post_url)): ?>
                <div>
                  <a href="<?= e($p->verification_post_url) ?>" target="_blank"
                     class="btn btn-outline-info btn-sm py-0 mt-1" style="font-size:10px;">
                    <i class="material-icons" style="font-size:11px;vertical-align:middle;">link</i>
                    پست تایید
                  </a>
                </div>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:10px;"><?= e(substr($p->created_at ?? '', 0, 10)) ?></td>
            <!-- عملیات -->
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <?php if($needsReview || $p->status === 'pending'): ?>
                  <button class="btn btn-success btn-sm py-0 px-1" title="تایید"
                          onclick="doAction(<?= (int)$p->id ?>, 'approve')">
                    <i class="material-icons" style="font-size:14px;">check</i>
                  </button>
                  <button class="btn btn-danger btn-sm py-0 px-1" title="رد"
                          onclick="doAction(<?= (int)$p->id ?>, 'reject')">
                    <i class="material-icons" style="font-size:14px;">close</i>
                  </button>
                <?php endif; ?>
                <?php if($p->status === 'verified'): ?>
                  <button class="btn btn-dark btn-sm py-0 px-1" title="تعلیق"
                          onclick="doAction(<?= (int)$p->id ?>, 'suspend')">
                    <i class="material-icons" style="font-size:14px;">block</i>
                  </button>
                <?php endif; ?>
                <?php if($p->status === 'suspended'): ?>
                  <button class="btn btn-success btn-sm py-0 px-1" title="رفع تعلیق"
                          onclick="doAction(<?= (int)$p->id ?>, 'approve')">
                    <i class="material-icons" style="font-size:14px;">lock_open</i>
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
  <?php if(($pages??1) > 1): ?>
  <div class="card-footer">
    <nav><ul class="pagination pagination-sm mb-0">
      <?php for($i=1;$i<=($pages??1);$i++): ?>
        <li class="page-item <?= $i===($page??1)?'active':'' ?>">
          <a class="page-link"
             href="?page=<?= $i ?>&status=<?= e($filters['status']??'') ?>&search=<?= e($filters['search']??'') ?>">
            <?= $i ?>
          </a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<script>
function doAction(id, decision) {
  let msg = '';
  if (decision === 'approve')  msg = 'آیا این پیج را تایید می‌کنید؟';
  if (decision === 'reject')   msg = 'دلیل رد پیج را وارد کنید:';
  if (decision === 'suspend')  msg = 'دلیل تعلیق را وارد کنید:';

  let reason = null;
  if (decision === 'reject' || decision === 'suspend') {
    reason = prompt(msg);
    if (reason === null) return;
  } else {
    if (!confirm(msg)) return;
  }

  fetch('<?= url('/admin/influencer/profiles/approve') ?>', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},
    body: JSON.stringify({profile_id: id, decision, reason})
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) location.reload();
    else alert(d.message || 'خطا');
  });
}
</script>

<?php $content=ob_get_clean(); include view_path('layouts.'.$layout); ?>
