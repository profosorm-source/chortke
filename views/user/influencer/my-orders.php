<?php $layout='user'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-primary">pending_actions</i> سفارش‌های دریافتی
  </h4>
  <a href="<?= url('/influencer') ?>" class="btn btn-outline-secondary btn-sm">بازگشت</a>
</div>

<?php if(empty($orders)): ?>
  <div class="card mt-4">
    <div class="card-body text-center py-5">
      <i class="material-icons text-muted" style="font-size:48px;">inbox</i>
      <h6 class="mt-2 text-muted">سفارشی دریافت نکرده‌اید.</h6>
    </div>
  </div>
<?php else: ?>

<?php foreach($orders as $o):
  $sl = $statusLabels ?? [];
  $sc = $statusClasses ?? [];
  $badgeMap = ['badge-success'=>'bg-success','badge-primary'=>'bg-primary','badge-warning'=>'bg-warning text-dark','badge-info'=>'bg-info','badge-danger'=>'bg-danger','badge-secondary'=>'bg-secondary','badge-orange'=>'bg-warning'];
  $badgeCls = $badgeMap[$sc[$o->status] ?? 'badge-secondary'] ?? 'bg-secondary';
?>
<div class="card mt-2">
  <div class="card-body">
    <div class="row align-items-center g-2">
      <!-- شناسه و اطلاعات -->
      <div class="col-md-4">
        <div class="d-flex align-items-center gap-2">
          <div class="text-muted small">#<?= e($o->id) ?></div>
          <span class="badge <?= $badgeCls ?>"><?= e($sl[$o->status] ?? $o->status) ?></span>
        </div>
        <div class="small mt-1">
          <strong><?= $o->order_type === 'story' ? 'استوری' : 'پست' ?></strong>
          · <?= $o->duration_hours ?? 24 ?> ساعت
          · <span class="text-success fw-bold"><?= number_format($o->influencer_earning ?? 0) ?></span>
        </div>
        <div class="text-muted" style="font-size:11px;"><?= e(substr($o->created_at ?? '', 0, 16)) ?></div>
      </div>

      <!-- اطلاعات سفارش -->
      <div class="col-md-4 small text-muted">
        <?php if(!empty($o->caption)): ?>
          <div class="text-truncate"><?= e($o->caption) ?></div>
        <?php endif; ?>
        <?php if(!empty($o->link)): ?>
          <a href="<?= e($o->link) ?>" target="_blank" class="d-block text-truncate">
            <i class="material-icons" style="font-size:12px;vertical-align:middle;">link</i>
            <?= e($o->link) ?>
          </a>
        <?php endif; ?>
        <?php if(!empty($o->preferred_publish_time)): ?>
          <div>زمان مطلوب: <?= e($o->preferred_publish_time) ?></div>
        <?php endif; ?>
      </div>

      <!-- دکمه‌های عملیات -->
      <div class="col-md-4 d-flex gap-1 flex-wrap justify-content-md-end">
        <?php if($o->status === 'paid'): ?>
          <button class="btn btn-success btn-sm" onclick="respond(<?= $o->id ?>, 'accept')">قبول</button>
          <button class="btn btn-outline-danger btn-sm" onclick="promptReject(<?= $o->id ?>)">رد</button>

        <?php elseif($o->status === 'accepted'): ?>
          <button class="btn btn-primary btn-sm" onclick="openProofModal(<?= $o->id ?>)">
            <i class="material-icons" style="font-size:15px;vertical-align:middle;">upload</i>
            ثبت مدرک انتشار
          </button>

        <?php elseif($o->status === 'awaiting_buyer_check'): ?>
          <span class="text-muted small">
            <i class="material-icons text-warning" style="font-size:15px;vertical-align:middle;">hourglass_empty</i>
            در انتظار تایید خریدار
          </span>

        <?php elseif(in_array($o->status, ['peer_resolution','escalated_to_admin'])): ?>
          <a href="<?= url('/influencer/orders/' . $o->id . '/dispute') ?>"
             class="btn btn-warning btn-sm text-white">
            <i class="material-icons" style="font-size:15px;vertical-align:middle;">gavel</i>
            پنل اختلاف
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- نمایش proof اگر داشت -->
    <?php if(!empty($o->proof_link) || !empty($o->proof_screenshot)): ?>
    <div class="mt-2 pt-2 border-top small">
      <span class="text-muted">مدرک:</span>
      <?php if(!empty($o->proof_link)): ?>
        <a href="<?= e($o->proof_link) ?>" target="_blank" class="ms-1">
          <i class="material-icons" style="font-size:13px;vertical-align:middle;">link</i>
          مشاهده لینک
        </a>
      <?php endif; ?>
      <?php if(!empty($o->proof_screenshot)): ?>
        <a href="<?= e($o->proof_screenshot) ?>" target="_blank" class="ms-2">
          <i class="material-icons" style="font-size:13px;vertical-align:middle;">image</i>
          تصویر
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if(($page??1) > 1 || count($orders) >= 20): ?>
<div class="d-flex justify-content-center mt-3">
  <nav><ul class="pagination pagination-sm">
    <?php if(($page??1) > 1): ?><li class="page-item"><a class="page-link" href="?page=<?= ($page??1)-1 ?>">قبلی</a></li><?php endif; ?>
    <li class="page-item active"><span class="page-link"><?= $page??1 ?></span></li>
    <?php if(count($orders) >= 20): ?><li class="page-item"><a class="page-link" href="?page=<?= ($page??1)+1 ?>">بعدی</a></li><?php endif; ?>
  </ul></nav>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Modal: ثبت مدرک -->
<div class="modal fade" id="proofModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ثبت مدرک انتشار</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="proofForm" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" id="proofOrderId" name="order_id">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">لینک پست/استوری <span class="text-danger">*</span></label>
            <input type="url" name="proof_link" class="form-control"
                   placeholder="https://www.instagram.com/p/..." required>
            <div class="form-text">لینک مستقیم پست یا استوری منتشرشده را وارد کنید.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">تصویر مدرک (اختیاری)</label>
            <input type="file" name="proof_screenshot" class="form-control" accept="image/*">
          </div>
          <div class="mb-3">
            <label class="form-label">توضیحات</label>
            <textarea name="proof_notes" class="form-control" rows="2"
                      placeholder="هر توضیح اضافه‌ای..."></textarea>
          </div>
          <div class="alert alert-info small mb-0">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">info</i>
            بعد از ثبت مدرک، به تبلیغ‌دهنده اطلاع داده می‌شود تا پیج شما را چک کند.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
          <button type="submit" class="btn btn-primary" id="proofSubmitBtn">ثبت مدرک</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: رد سفارش -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">رد سفارش</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">دلیل رد (اختیاری)</label>
        <textarea id="rejectReason" class="form-control" rows="2"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
        <button type="button" class="btn btn-danger" id="rejectConfirmBtn">تایید رد</button>
      </div>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]')?.content
          || '<?= csrf_token() ?>';

function respond(id, action) {
  fetch('/influencer/orders/' + id + '/respond', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify({action})
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload();
    else alert(d.message || 'خطا');
  });
}

let rejectOrderId = null;
function promptReject(id) {
  rejectOrderId = id;
  document.getElementById('rejectReason').value = '';
  new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
document.getElementById('rejectConfirmBtn').onclick = function() {
  respond(rejectOrderId, 'reject');
  bootstrap.Modal.getInstance(document.getElementById('rejectModal')).hide();
};

function openProofModal(id) {
  document.getElementById('proofOrderId').value = id;
  document.getElementById('proofForm').reset();
  document.getElementById('proofOrderId').value = id;
  new bootstrap.Modal(document.getElementById('proofModal')).show();
}

document.getElementById('proofForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const id = document.getElementById('proofOrderId').value;
  const btn = document.getElementById('proofSubmitBtn');
  btn.disabled = true; btn.textContent = 'در حال ارسال...';
  const fd = new FormData(this);
  fetch('/influencer/orders/' + id + '/proof', {
    method: 'POST',
    headers: {'X-CSRF-TOKEN': csrf},
    body: fd
  }).then(r => r.json()).then(d => {
    if (d.success) { location.reload(); }
    else { alert(d.message || 'خطا'); btn.disabled=false; btn.textContent='ثبت مدرک'; }
  }).catch(() => { btn.disabled=false; btn.textContent='ثبت مدرک'; });
});
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>