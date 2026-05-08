<?php $title='درخواست‌های تایید اینفلوئنسر'; $layout='admin'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-primary">check_circle</i> درخواست‌های تایید اینفلوئنسر
  </h4>
  <a href="<?= url('/admin/influencer/profiles') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="material-icons" style="font-size:15px;vertical-align:middle;">arrow_back</i> بازگشت به پروفایل‌ها
  </a>
</div>

<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="card-title mb-0">لیست درخواست‌های ثبت‌شده</h6>
    <span class="badge bg-info"><?= number_format($total ?? 0) ?> مورد</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:12px;">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>پیج</th>
            <th>کاربر</th>
            <th>کد تایید</th>
            <th>لینک پست</th>
            <th>ثبت شده</th>
            <th style="min-width:150px;">عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($requests)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">رکوردی یافت نشد.</td></tr>
          <?php else: ?>
            <?php foreach($requests as $idx => $req): ?>
              <tr>
                <td class="text-muted"><?= (((int)($page??1)-1)*30) + $idx + 1 ?></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div>
                      <div class="fw-bold">@<?= e($req->username ?? '—') ?></div>
                      <a href="<?= e($req->page_url ?? '#') ?>" target="_blank" class="text-muted" style="font-size:10px;">مشاهده پیج</a>
                    </div>
                  </div>
                </td>
                <td><?= e($req->full_name ?? $req->email ?? '—') ?></td>
                <td><code style="font-size:11px;"><?= e($req->code) ?></code></td>
                <td>
                  <?php if(!empty($req->proof_url)): ?>
                    <a href="<?= e($req->proof_url) ?>" target="_blank" class="btn btn-outline-info btn-sm py-0 px-1" style="font-size:11px;">
                      <i class="material-icons" style="font-size:13px;vertical-align:middle;">link</i>
                      مشاهده
                    </a>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
                <td><?= e(substr($req->submitted_at ?? $req->created_at ?? '', 0, 16)) ?></td>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <button class="btn btn-success btn-sm py-0 px-1" onclick="handleVerification(<?= (int)$req->id ?>, 'approve')" title="تایید">
                      <i class="material-icons" style="font-size:14px;">check</i>
                    </button>
                    <button class="btn btn-danger btn-sm py-0 px-1" onclick="handleVerification(<?= (int)$req->id ?>, 'reject')" title="رد">
                      <i class="material-icons" style="font-size:14px;">close</i>
                    </button>
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
          <a class="page-link" href="?page=<?= $i ?>">
            <?= $i ?>
          </a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<script>
function handleVerification(id, decision) {
  let reason = '';
  if (decision === 'reject') {
    reason = prompt('لطفاً دلیل رد درخواست را وارد کنید:');
    if (reason === null || reason.trim().length < 5) {
      alert('دلیل باید حداقل ۵ کاراکتر باشد.');
      return;
    }
  }

  fetch('<?= url('/admin/influencer/verifications/') ?>' + decision, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': '<?= csrf_token() ?>'
    },
    body: JSON.stringify({ verification_id: id, reason })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      location.reload();
    } else {
      alert(d.message || 'خطا در اجرای عملیات.');
    }
  })
  .catch(() => alert('خطا در اتصال.'));
}
</script>

<?php $content=ob_get_clean(); include view_path('layouts.'.$layout); ?>