<?php $title = 'مدیریت SEO Ad'; $layout = 'admin'; ob_start(); ?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="page-title mb-1">
        <span class="material-icons text-primary">manage_search</span> مدیریت آگهی‌های SEO
      </h4>
    </div>
  </div>

  <!-- آمار کلی -->
  <div class="row mb-4">
    <div class="col-md-3">
      <div class="card">
        <div class="card-body">
          <h6 class="text-muted mb-2">کل آگهی‌ها</h6>
          <h3 class="mb-0"><?= $stats['total_ads'] ?? 0 ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card">
        <div class="card-body">
          <h6 class="text-muted mb-2">فعال</h6>
          <h3 class="mb-0 text-success"><?= $stats['active_ads'] ?? 0 ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card">
        <div class="card-body">
          <h6 class="text-muted mb-2">اجراها (30 روز)</h6>
          <h3 class="mb-0"><?= $stats['trend']['summary']['total'] ?? 0 ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card">
        <div class="card-body">
          <h6 class="text-muted mb-2">میانگین روزانه</h6>
          <h3 class="mb-0"><?= round(($stats['trend']['summary']['average'] ?? 0), 1) ?></h3>
        </div>
      </div>
    </div>
  </div>

  <!-- فیلتر -->
  <div class="card mb-3">
    <div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
          <select name="status" class="form-select form-select-sm">
            <option value="">همه وضعیت‌ها</option>
            <option value="pending"   <?= ($status??'')==='pending'   ?'selected':'' ?>>در انتظار تایید</option>
            <option value="active"    <?= ($status??'')==='active'    ?'selected':'' ?>>فعال</option>
            <option value="paused"    <?= ($status??'')==='paused'    ?'selected':'' ?>>متوقف</option>
            <option value="rejected"  <?= ($status??'')==='rejected'  ?'selected':'' ?>>رد شده</option>
            <option value="exhausted" <?= ($status??'')==='exhausted' ?'selected':'' ?>>بودجه تمام</option>
          </select>
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary btn-sm w-100">فیلتر</button>
        </div>
        <div class="col-md-2">
          <a href="<?= url('/admin/seo-ad') ?>" class="btn btn-outline-secondary btn-sm w-100">پاک</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <?php if(empty($items)): ?>
        <div class="text-center py-5 text-muted">
          <span class="material-icons" style="font-size:48px;">manage_search</span>
          <div class="mt-2">موردی یافت نشد.</div>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>کاربر</th>
              <th>کلمه کلیدی / سایت</th>
              <th>بودجه</th>
              <th>پرداخت‌ها</th>
              <th>اجراها</th>
              <th>وضعیت</th>
              <th>تاریخ</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($items as $item): ?>
            <?php
              $sc = ['pending'=>'warning','active'=>'success','paused'=>'secondary','rejected'=>'danger','exhausted'=>'dark'];
              $sl = ['pending'=>'در انتظار','active'=>'فعال','paused'=>'متوقف','rejected'=>'رد شده','exhausted'=>'تمام'];
              $st = $item->status ?? 'pending';
              $spent = ($item->budget ?? 0) - ($item->remaining_budget ?? 0);
            ?>
            <tr>
              <td><?= e($item->id) ?></td>
              <td>
                <div class="fw-bold small"><?= e($item->user_name ?? '—') ?></div>
                <small class="text-muted"><?= e($item->user_email ?? '') ?></small>
              </td>
              <td>
                <span class="badge bg-info mb-1"><?= e($item->keyword) ?></span><br>
                <a href="<?= e($item->site_url) ?>" target="_blank"
                   class="text-truncate d-inline-block small" style="max-width:180px;">
                  <?= e($item->site_url) ?>
                </a>
              </td>
              <td>
                <div class="small"><?= number_format($item->budget ?? 0) ?></div>
                <div style="width:80px;">
                  <?php $pct = ($item->budget ?? 0) > 0 ? ($spent / $item->budget) * 100 : 0; ?>
                  <div class="progress" style="height:4px;">
                    <div class="progress-bar bg-danger" style="width:<?= min(100,$pct) ?>%"></div>
                  </div>
                </div>
              </td>
              <td>
                <div class="small">حداقل: <?= number_format($item->min_payout ?? 0) ?></div>
                <div class="small">حداکثر: <?= number_format($item->max_payout ?? 0) ?></div>
              </td>
              <td>
                <div class="small"><?= number_format($item->executions_count ?? 0) ?></div>
              </td>
              <td>
                <span class="badge bg-<?= $sc[$st] ?>"><?= $sl[$st] ?></span>
              </td>
              <td style="font-size:11px;"><?= e(substr($item->created_at ?? '', 0, 10)) ?></td>
              <td>
                <div class="d-flex gap-1">
                  <?php if($st === 'pending'): ?>
                  <button class="btn btn-success btn-sm btn-approve" data-id="<?= e($item->id) ?>" title="تایید">
                    <span class="material-icons" style="font-size:14px;">check</span>
                  </button>
                  <button class="btn btn-danger btn-sm btn-reject" data-id="<?= e($item->id) ?>" title="رد">
                    <span class="material-icons" style="font-size:14px;">close</span>
                  </button>
                  <?php elseif($st === 'active'): ?>
                  <button class="btn btn-warning btn-sm btn-pause-ad" data-id="<?= e($item->id) ?>" title="توقف">
                    <span class="material-icons" style="font-size:14px;">pause</span>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- مودال رد -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">دلیل رد</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <textarea id="rejectReason" class="form-control" rows="3" placeholder="دلیل رد را بنویسید..."></textarea>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">انصراف</button>
        <button class="btn btn-danger btn-sm" id="btnConfirmReject">رد کردن</button>
      </div>
    </div>
  </div>
</div>

<script>
function csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; }
let rejectId = null;

document.querySelectorAll('.btn-approve').forEach(btn => {
  btn.addEventListener('click', function() {
    if(!confirm('تایید این آگهی؟')) return;
    fetch(`/admin/seo-ad/${this.dataset.id}/approve`, {
      method:'POST', headers:{'X-CSRF-Token': csrf()}
    }).then(r=>r.json()).then(d => { if(d.success) location.reload(); else alert('خطا'); });
  });
});

document.querySelectorAll('.btn-reject').forEach(btn => {
  btn.addEventListener('click', function() {
    rejectId = this.dataset.id;
    document.getElementById('rejectReason').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
  });
});

document.getElementById('btnConfirmReject')?.addEventListener('click', function() {
  const reason = document.getElementById('rejectReason').value.trim();
  if(!reason) { alert('دلیل رد الزامی است.'); return; }
  const fd = new FormData();
  fd.append('reason', reason);
  fetch(`/admin/seo-ad/${rejectId}/reject`, {
    method:'POST', headers:{'X-CSRF-Token': csrf()}, body: fd
  }).then(r=>r.json()).then(d => {
    if(d.success) location.reload();
    else alert('خطا');
  });
});

document.querySelectorAll('.btn-pause-ad').forEach(btn => {
  btn.addEventListener('click', function() {
    if(!confirm('توقف این آگهی؟')) return;
    fetch(`/admin/seo-ad/${this.dataset.id}/pause`, {
      method:'POST', headers:{'X-CSRF-Token': csrf()}
    }).then(r=>r.json()).then(d => { if(d.success) location.reload(); else alert('خطا'); });
  });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
