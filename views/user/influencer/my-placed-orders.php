<?php $layout='user'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><i class="material-icons text-primary">list_alt</i> سفارش‌های تبلیغ من</h4>
    <p class="text-muted mb-0 small">سفارش‌هایی که برای تبلیغ ثبت کرده‌اید</p>
  </div>
  <a href="<?= url('/influencer/ads') ?>" class="btn btn-primary btn-sm">
    <i class="material-icons" style="font-size:15px;vertical-align:middle;">add</i> سفارش جدید
  </a>
</div>

<?php if(empty($orders)): ?>
<div class="card mt-4">
  <div class="card-body text-center py-5">
    <i class="material-icons text-muted" style="font-size:64px;">list_alt</i>
    <h5 class="mt-3 text-muted">سفارشی ثبت نکرده‌اید</h5>
    <a href="<?= url('/influencer/ads') ?>" class="btn btn-primary mt-2">انتخاب اینفلوئنسر</a>
  </div>
</div>
<?php else: ?>

<?php
$sl = $statusLabels ?? [];
$sc = $statusClasses ?? [];
$badgeMap = ['badge-success'=>'bg-success','badge-primary'=>'bg-primary','badge-warning'=>'bg-warning text-dark','badge-info'=>'bg-info','badge-danger'=>'bg-danger','badge-secondary'=>'bg-secondary','badge-orange'=>'bg-warning'];
?>

<?php foreach($orders as $o):
  $st = $o->status ?? 'paid';
  $badgeCls = $badgeMap[$sc[$st] ?? 'badge-secondary'] ?? 'bg-secondary';
  $isAwaitingCheck = $st === 'awaiting_buyer_check';
  $deadline = $o->buyer_check_deadline ?? null;
?>
<div class="card mt-2 <?= $isAwaitingCheck ? 'border-warning' : '' ?>">
  <?php if($isAwaitingCheck): ?>
  <div class="card-header bg-warning bg-opacity-10 py-1">
    <small class="fw-bold text-warning">
      <i class="material-icons" style="font-size:14px;vertical-align:middle;">timer</i>
      نیاز به بررسی شما دارد
      <?php if($deadline): ?>
        · مهلت: <?= e(substr($deadline, 0, 16)) ?>
      <?php endif; ?>
    </small>
  </div>
  <?php endif; ?>
  <div class="card-body">
    <div class="row align-items-center g-2">
      <!-- اینفلوئنسر -->
      <div class="col-md-3">
        <div class="fw-bold small">@<?= e($o->influencer_username ?? '—') ?></div>
        <div class="text-muted" style="font-size:11px;">
          <?= $o->order_type === 'story' ? 'استوری' : 'پست' ?>
          · <?= $o->duration_hours ?? 24 ?>ساعت
        </div>
        <div class="text-muted" style="font-size:11px;"><?= e(substr($o->created_at ?? '', 0, 10)) ?></div>
      </div>

      <!-- مبلغ -->
      <div class="col-md-2 text-center">
        <div class="fw-bold text-success small"><?= number_format($o->price ?? 0) ?></div>
        <div class="text-muted" style="font-size:10px;">مبلغ سفارش</div>
      </div>

      <!-- وضعیت -->
      <div class="col-md-2 text-center">
        <span class="badge <?= $badgeCls ?>"><?= e($sl[$st] ?? $st) ?></span>
      </div>

      <!-- مدرک -->
      <div class="col-md-2 small text-muted">
        <?php if(!empty($o->proof_link)): ?>
          <a href="<?= e($o->proof_link) ?>" target="_blank" class="d-flex align-items-center gap-1 text-primary">
            <i class="material-icons" style="font-size:13px;">open_in_new</i> مشاهده مدرک
          </a>
        <?php elseif($o->proof_screenshot): ?>
          <a href="<?= e($o->proof_screenshot) ?>" target="_blank">
            <i class="material-icons" style="font-size:13px;vertical-align:middle;">image</i> تصویر
          </a>
        <?php else: ?>
          <span>—</span>
        <?php endif; ?>
      </div>

      <!-- عملیات -->
      <div class="col-md-3 d-flex gap-1 justify-content-md-end flex-wrap">
        <?php if($isAwaitingCheck): ?>
          <button class="btn btn-success btn-sm" onclick="confirmOrder(<?= (int)$o->id ?>)">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">check_circle</i>
            تایید انجام
          </button>
          <button class="btn btn-outline-danger btn-sm" onclick="openDisputeModal(<?= (int)$o->id ?>)">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">report_problem</i>
            اعتراض
          </button>
        <?php elseif(in_array($st, ['peer_resolution','escalated_to_admin'])): ?>
          <a href="<?= url('/influencer/orders/' . (int)$o->id . '/dispute') ?>"
             class="btn btn-warning btn-sm text-white">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">gavel</i> پنل اختلاف
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- اطلاعات proof -->
    <?php if($isAwaitingCheck && (!empty($o->proof_link) || !empty($o->proof_notes))): ?>
    <div class="mt-2 pt-2 border-top small bg-light rounded p-2">
      <strong>مدرک اینفلوئنسر:</strong>
      <?php if(!empty($o->proof_link)): ?>
        <a href="<?= e($o->proof_link) ?>" target="_blank" class="ms-1 text-primary">
          <i class="material-icons" style="font-size:12px;vertical-align:middle;">link</i>
          مشاهده پست
        </a>
      <?php endif; ?>
      <?php if(!empty($o->proof_notes)): ?>
        <div class="text-muted mt-1"><?= e($o->proof_notes) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if(($page??1) > 1 || count($orders) >= 20): ?>
<div class="d-flex justify-content-center mt-3">
  <nav><ul class="pagination pagination-sm">
    <?php if(($page??1)>1): ?><li class="page-item"><a class="page-link" href="?page=<?= ($page??1)-1 ?>">قبلی</a></li><?php endif; ?>
    <li class="page-item active"><span class="page-link"><?= $page??1 ?></span></li>
    <?php if(count($orders)>=20): ?><li class="page-item"><a class="page-link" href="?page=<?= ($page??1)+1 ?>">بعدی</a></li><?php endif; ?>
  </ul></nav>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Modal: اعتراض -->
<div class="modal fade" id="disputeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ثبت اعتراض</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning small">
          <i class="material-icons" style="font-size:14px;vertical-align:middle;">info</i>
          با ثبت اعتراض، یک گفت‌وگو بین شما و اینفلوئنسر شروع می‌شود.
          اگر توافق نشد، پرونده به مدیر ارجاع می‌شود.
        </div>
        <label class="form-label">دلیل اعتراض <span class="text-danger">*</span></label>
        <textarea id="disputeReason" class="form-control" rows="3"
                  placeholder="مثلا: استوری منتشر نشده / محتوا متفاوت بود / ..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
        <button type="button" class="btn btn-danger" id="disputeSubmitBtn">ثبت اعتراض</button>
      </div>
    </div>
  </div>
</div>

<script>
const csrf = '<?= csrf_token() ?>';
let disputeOrderId = null;

function confirmOrder(id) {
  if (!confirm('آیا از انجام صحیح سفارش اطمینان دارید؟ مبلغ به اینفلوئنسر پرداخت می‌شود.')) return;
  fetch('/influencer/ads/orders/' + id + '/confirm', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify({})
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload();
    else alert(d.message || 'خطا');
  });
}

function openDisputeModal(id) {
  disputeOrderId = id;
  document.getElementById('disputeReason').value = '';
  new bootstrap.Modal(document.getElementById('disputeModal')).show();
}

document.getElementById('disputeSubmitBtn').onclick = function() {
  const reason = document.getElementById('disputeReason').value.trim();
  if (!reason) { alert('دلیل اعتراض الزامی است.'); return; }
  const btn = this;
  btn.disabled = true; btn.textContent = 'در حال ارسال...';
  fetch('/influencer/ads/orders/' + disputeOrderId + '/dispute', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
    body: JSON.stringify({reason})
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload();
    else { alert(d.message || 'خطا'); btn.disabled=false; btn.textContent='ثبت اعتراض'; }
  });
};
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>