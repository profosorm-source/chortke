<?php ob_start(); ?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="page-title mb-1">
        <span class="material-icons text-primary" style="vertical-align:middle;">sports_soccer</span>
        مدیریت پیش‌بینی بازی‌ها
      </h4>
      <p class="text-muted mb-0" style="font-size:12px;">تعریف بازی · مشاهده شرط‌ها · ثبت نتیجه · تسویه خودکار</p>
    </div>
    <a href="<?= url('/admin/prediction/create') ?>" class="btn btn-primary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> بازی جدید
    </a>
  </div>

  <?php if($flash = session_flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= e($flash) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>
  <?php if($flash = session_flash('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= e($flash) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>

  <!-- فیلترها -->
  <div class="card mb-3">
    <div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
          <select name="status" class="form-select form-select-sm">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach(['open'=>'باز','closed'=>'بسته','finished'=>'پایان یافته','cancelled'=>'لغو شده'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= ($filters['status']===$k)?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <select name="sport_type" class="form-select form-select-sm">
            <option value="">همه ورزش‌ها</option>
            <?php foreach($sportTypes as $k=>$v): ?>
              <option value="<?= $k ?>" <?= ($filters['sport_type']===$k)?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="جستجو در عنوان یا نام تیم..." value="<?= e($filters['search']) ?>">
        </div>
        <div class="col-md-1"><button class="btn btn-primary btn-sm w-100">فیلتر</button></div>
        <div class="col-md-1"><a href="<?= url('/admin/prediction') ?>" class="btn btn-outline-secondary btn-sm w-100">پاک</a></div>
      </form>
    </div>
  </div>

  <!-- جدول -->
  <div class="card">
    <div class="card-body p-0">
      <?php if(empty($games)): ?>
        <div class="text-center py-5 text-muted">
          <span class="material-icons" style="font-size:48px;color:#ccc;">sports_soccer</span><br>
          هیچ بازی‌ای یافت نشد.
          <br><a href="<?= url('/admin/prediction/create') ?>" class="btn btn-primary btn-sm mt-2">اولین بازی را تعریف کن</a>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>بازی</th>
              <th>ورزش</th>
              <th>تاریخ بازی</th>
              <th>ددلاین</th>
              <th>استخر (USDT)</th>
              <th>شرکت‌کننده</th>
              <th>وضعیت</th>
              <th>نتیجه</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($games as $g):
            $statusMap = ['open'=>['success','باز'],'closed'=>['warning','بسته'],'finished'=>['primary','پایان'],'cancelled'=>['secondary','لغو']];
            [$sc, $sl] = $statusMap[$g->status] ?? ['secondary', $g->status];
          ?>
          <tr>
            <td class="text-muted" style="font-size:12px;"><?= e($g->id) ?></td>
            <td>
              <div class="fw-bold"><?= e($g->team_home) ?> <small class="text-muted">vs</small> <?= e($g->team_away) ?></div>
              <small class="text-muted"><?= e($g->title) ?></small>
            </td>
            <td><span class="badge bg-secondary"><?= e($sportTypes[$g->sport_type] ?? $g->sport_type) ?></span></td>
            <td style="font-size:12px;"><?= e(substr((string)($g->match_date ?? ''), 0, 16)) ?></td>
            <td style="font-size:12px;"><?= e(substr((string)($g->bet_deadline ?? ''), 0, 16)) ?></td>
            <td class="fw-bold text-success"><?= number_format((float)($g->total_pool ?? 0), 2) ?></td>
            <td><?= number_format((int)($g->total_bets ?? 0)) ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= $sl ?></span></td>
            <td>
              <?php if($g->result): ?>
                <span class="badge bg-info"><?= ['home'=>'خانه','away'=>'مهمان','draw'=>'مساوی'][$g->result] ?? $g->result ?></span>
                <?php if($g->winners_paid): ?><span class="badge bg-success ms-1">✓ پرداخت شده</span><?php endif; ?>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= url("/admin/prediction/{$g->id}") ?>" class="btn btn-outline-info btn-sm" title="جزئیات">
                  <span class="material-icons" style="font-size:15px;">visibility</span>
                </a>
                <?php if($g->status === 'open'): ?>
                  <button class="btn btn-outline-warning btn-sm btn-close-betting" data-id="<?= (int)$g->id ?>" title="بستن شرط‌گیری">
                    <span class="material-icons" style="font-size:15px;">lock</span>
                  </button>
                  <button class="btn btn-warning btn-sm btn-settle" data-id="<?= (int)$g->id ?>"
                          data-home="<?= e($g->team_home) ?>" data-away="<?= e($g->team_away) ?>" title="ثبت نتیجه">
                    <span class="material-icons" style="font-size:15px;">flag</span>
                  </button>
                  <button class="btn btn-danger btn-sm btn-cancel" data-id="<?= (int)$g->id ?>" title="لغو بازی">
                    <span class="material-icons" style="font-size:15px;">cancel</span>
                  </button>
                <?php elseif($g->status === 'closed'): ?>
                  <button class="btn btn-warning btn-sm btn-settle" data-id="<?= (int)$g->id ?>"
                          data-home="<?= e($g->team_home) ?>" data-away="<?= e($g->team_away) ?>" title="ثبت نتیجه">
                    <span class="material-icons" style="font-size:15px;">flag</span>
                  </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if($totalPages > 1): ?>
      <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
        <small class="text-muted">
          نمایش <?= min($perPage, $total - ($page-1)*$perPage) ?> از <?= number_format($total) ?> بازی
        </small>
        <nav><ul class="pagination pagination-sm mb-0">
          <?php for($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p===$page?'active':'' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page'=>$p])) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
        </ul></nav>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal ثبت نتیجه -->
<div class="modal fade" id="settleModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">ثبت نتیجه بازی</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3" id="settleGameTitle"></p>
        <div class="d-grid gap-2">
          <button class="btn btn-outline-primary btn-result" data-result="home">
            🏠 <span id="homeTeamLabel">خانه</span> برنده شد
          </button>
          <button class="btn btn-outline-secondary btn-result" data-result="draw">
            🤝 مساوی
          </button>
          <button class="btn btn-outline-success btn-result" data-result="away">
            ✈️ <span id="awayTeamLabel">مهمان</span> برنده شد
          </button>
        </div>
        <p class="text-danger small mt-2 mb-0">
          <span class="material-icons" style="font-size:13px;vertical-align:middle;">warning</span>
          این عملیات جوایز را به صورت خودکار پرداخت می‌کند و غیر قابل بازگشت است.
        </p>
      </div>
    </div>
  </div>
</div>

<script>
let settleGameId = null;
function csrf() { return document.querySelector('meta[name=csrf-token]')?.content ?? ''; }

function showToast(msg, type='success') {
  const el = document.createElement('div');
  el.className = `alert alert-${type} position-fixed top-0 end-0 m-3`;
  el.style.zIndex = 9999;
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

document.querySelectorAll('.btn-settle').forEach(btn => {
  btn.addEventListener('click', function() {
    settleGameId = this.dataset.id;
    document.getElementById('settleGameTitle').textContent = `بازی #${settleGameId}`;
    document.getElementById('homeTeamLabel').textContent = this.dataset.home;
    document.getElementById('awayTeamLabel').textContent = this.dataset.away;
    new bootstrap.Modal(document.getElementById('settleModal')).show();
  });
});

document.querySelectorAll('.btn-result').forEach(btn => {
  btn.addEventListener('click', function() {
    if (!settleGameId) return;
    if (!confirm('آیا مطمئن هستید؟ این عملیات جوایز را پرداخت می‌کند.')) return;

    this.disabled = true;
    const resultLabel = this.textContent.trim();

    fetch(`/admin/prediction/${settleGameId}/settle`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify({ result: this.dataset.result })
    })
    .then(r => r.json())
    .then(d => {
      bootstrap.Modal.getInstance(document.getElementById('settleModal'))?.hide();
      showToast(d.message, d.success ? 'success' : 'danger');
      if (d.success) setTimeout(() => location.reload(), 1500);
    })
    .catch(() => showToast('خطای شبکه', 'danger'))
    .finally(() => this.disabled = false);
  });
});

document.querySelectorAll('.btn-cancel').forEach(btn => {
  btn.addEventListener('click', function() {
    if (!confirm('لغو این بازی و برگشت وجه به همه شرط‌بندان؟')) return;
    const id = this.dataset.id;
    fetch(`/admin/prediction/${id}/cancel`, {
      method: 'POST', headers: { 'X-CSRF-Token': csrf() }
    })
    .then(r => r.json())
    .then(d => {
      showToast(d.message, d.success ? 'success' : 'danger');
      if (d.success) setTimeout(() => location.reload(), 1500);
    });
  });
});

document.querySelectorAll('.btn-close-betting').forEach(btn => {
  btn.addEventListener('click', function() {
    if (!confirm('شرط‌گیری این بازی بسته شود؟')) return;
    const id = this.dataset.id;
    fetch(`/admin/prediction/${id}/close-betting`, {
      method: 'POST', headers: { 'X-CSRF-Token': csrf() }
    })
    .then(r => r.json())
    .then(d => {
      showToast(d.message, d.success ? 'success' : 'danger');
      if (d.success) setTimeout(() => location.reload(), 1500);
    });
  });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
