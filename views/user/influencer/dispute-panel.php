<?php $layout='user'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-warning">gavel</i>
    پنل اختلاف — سفارش #<?= e($order->id ?? '') ?>
  </h4>
  <a href="<?= url($role==='influencer' ? '/influencer/orders' : '/influencer/ads/my-orders') ?>"
     class="btn btn-outline-secondary btn-sm">بازگشت</a>
</div>

<?php
$disputeStatus = $dispute->status ?? null;
$statusLabels  = ['open_peer'=>'گفت‌وگوی طرفین','resolved_peer'=>'حل دوستانه','escalated'=>'ارجاع به مدیر','resolved_admin'=>'رأی مدیر صادر شد','closed'=>'بسته شده'];
$isClosed = in_array($disputeStatus, ['resolved_peer','resolved_admin','closed']);
?>

<!-- خلاصه سفارش -->
<div class="card mt-3">
  <div class="card-body">
    <div class="row g-2 small">
      <div class="col-md-3">
        <span class="text-muted">نوع سفارش:</span>
        <strong><?= $order->order_type === 'story' ? 'استوری' : 'پست' ?></strong>
      </div>
      <div class="col-md-3">
        <span class="text-muted">مبلغ:</span>
        <strong class="text-success"><?= number_format($order->price ?? 0) ?></strong>
      </div>
      <div class="col-md-3">
        <span class="text-muted">وضعیت اختلاف:</span>
        <span class="badge bg-warning text-dark"><?= $statusLabels[$disputeStatus] ?? '—' ?></span>
      </div>
      <div class="col-md-3">
        <?php if(!empty($order->proof_link)): ?>
          <a href="<?= e($order->proof_link) ?>" target="_blank" class="btn btn-outline-info btn-sm">
            <i class="material-icons" style="font-size:13px;vertical-align:middle;">open_in_new</i>
            مشاهده مدرک
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- پیام‌ها -->
<div class="card mt-3">
  <div class="card-header">
    <h6 class="card-title mb-0">گفت‌وگوی طرفین</h6>
  </div>
  <div class="card-body p-3" id="messagesBox" style="max-height:400px;overflow-y:auto;">
    <?php if(empty($messages)): ?>
      <p class="text-muted text-center small">هنوز پیامی ارسال نشده است.</p>
    <?php else: ?>
      <?php foreach($messages as $m):
        $isMine = (int)$m->user_id === (int)$userId;
        $roleLabel = $m->role === 'admin' ? 'مدیر' : ($m->role === 'customer' ? 'تبلیغ‌دهنده' : 'اینفلوئنسر');
      ?>
      <div class="d-flex <?= $isMine ? 'justify-content-end' : 'justify-content-start' ?> mb-2">
        <div class="rounded p-2 <?= $isMine ? 'bg-primary text-white' : 'bg-light' ?>"
             style="max-width:75%;">
          <div class="small fw-bold mb-1 <?= $isMine ? 'text-white-50' : 'text-muted' ?>">
            <?= e($m->sender_name ?? $roleLabel) ?>
          </div>
          <div style="white-space:pre-wrap;font-size:13px;"><?= e($m->message) ?></div>
          <?php if(!empty($m->attachment)): ?>
            <div class="mt-1">
              <a href="<?= e($m->attachment) ?>" target="_blank"
                 class="small <?= $isMine ? 'text-white' : 'text-primary' ?>">
                <i class="material-icons" style="font-size:12px;vertical-align:middle;">attach_file</i>
                فایل پیوست
              </a>
            </div>
          <?php endif; ?>
          <div class="small mt-1 <?= $isMine ? 'text-white-50' : 'text-muted' ?>" style="font-size:10px;">
            <?= e(substr($m->created_at ?? '', 0, 16)) ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ارسال پیام -->
  <?php if(!$isClosed && $dispute): ?>
  <div class="card-footer">
    <div class="d-flex gap-2 align-items-end">
      <textarea id="msgText" class="form-control form-control-sm" rows="2"
                placeholder="پیام خود را بنویسید..."></textarea>
      <div class="d-flex flex-column gap-1">
        <label class="btn btn-outline-secondary btn-sm mb-0" title="پیوست فایل">
          <i class="material-icons" style="font-size:15px;">attach_file</i>
          <input type="file" id="msgAttachment" style="display:none;" accept="image/*,.pdf">
        </label>
        <button class="btn btn-primary btn-sm" onclick="sendMsg()">
          <i class="material-icons" style="font-size:15px;">send</i>
        </button>
      </div>
    </div>
    <div id="attachName" class="small text-muted mt-1"></div>
  </div>
  <?php endif; ?>
</div>

<!-- دکمه‌های عملیات -->
<?php if(!$isClosed && $dispute && $disputeStatus === 'open_peer'): ?>
<div class="card mt-3">
  <div class="card-body">
    <div class="row g-2">
      <!-- توافق دوطرفه -->
      <?php if($role === 'influencer'): ?>
      <div class="col-md-6">
        <div class="card border-success h-100">
          <div class="card-body text-center py-3">
            <i class="material-icons text-success" style="font-size:36px;">handshake</i>
            <h6 class="mt-1">پیشنهاد توافق</h6>
            <p class="small text-muted mb-2">اگر با تبلیغ‌دهنده به توافق رسیدید اینجا ثبت کنید.</p>
            <button class="btn btn-success btn-sm" onclick="openAgreementModal()">ثبت توافق</button>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <!-- ارجاع به مدیر -->
      <div class="col-md-<?= $role === 'influencer' ? '6' : '12' ?>">
        <div class="card border-danger h-100">
          <div class="card-body text-center py-3">
            <i class="material-icons text-danger" style="font-size:36px;">escalator_warning</i>
            <h6 class="mt-1">ارجاع به مدیر</h6>
            <p class="small text-muted mb-2">اگر نتوانستید توافق کنید پرونده را به مدیر بسپارید.</p>
            <button class="btn btn-outline-danger btn-sm" onclick="escalateDispute()">ارجاع به مدیر</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php elseif($disputeStatus === 'escalated'): ?>
<div class="alert alert-info mt-3">
  <i class="material-icons" style="font-size:16px;vertical-align:middle;">hourglass_top</i>
  پرونده به مدیر ارجاع داده شده. منتظر رأی مدیر باشید.
</div>
<?php elseif(in_array($disputeStatus, ['resolved_peer','resolved_admin'])): ?>
<div class="alert alert-success mt-3">
  <i class="material-icons" style="font-size:16px;vertical-align:middle;">check_circle</i>
  اختلاف حل شد.
  <?php if(!empty($dispute->resolution_note)): ?>
    <div class="mt-1 small"><?= e($dispute->resolution_note) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Modal توافق -->
<div class="modal fade" id="agreementModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ثبت توافق</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">نتیجه توافق</label>
          <select id="verdictSelect" class="form-select">
            <option value="favor_influencer">سفارش انجام شده — پرداخت به اینفلوئنسر</option>
            <option value="favor_customer">سفارش انجام نشده — بازگشت وجه</option>
            <option value="partial">تسویه جزئی (۵۰٪)</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">توضیح توافق</label>
          <textarea id="agreementNote" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
        <button type="button" class="btn btn-success" onclick="submitAgreement()">ثبت توافق</button>
      </div>
    </div>
  </div>
</div>

<script>
const csrf   = '<?= csrf_token() ?>';
const orderId = <?= (int)($order->id ?? 0) ?>;
const role   = '<?= e($role) ?>';

document.getElementById('msgAttachment')?.addEventListener('change', function() {
  document.getElementById('attachName').textContent = this.files[0]?.name || '';
});

function sendMsg() {
  const text = document.getElementById('msgText').value.trim();
  if (!text) return;
  const fd = new FormData();
  fd.append('_token', csrf);
  fd.append('message', text);
  const file = document.getElementById('msgAttachment')?.files[0];
  if (file) fd.append('attachment', file);

  fetch('/influencer/orders/' + orderId + '/dispute/message', {
    method: 'POST', headers: {'X-CSRF-TOKEN': csrf}, body: fd
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload();
    else alert(d.message || 'خطا');
  });
}

function escalateDispute() {
  if (!confirm('آیا می‌خواهید پرونده را به مدیر ارجاع دهید؟')) return;
  fetch('/influencer/orders/' + orderId + '/dispute/escalate', {
    method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-TOKEN': csrf}, body: '{}'
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload();
    else alert(d.message || 'خطا');
  });
}

function openAgreementModal() {
  new bootstrap.Modal(document.getElementById('agreementModal')).show();
}

function submitAgreement() {
  const verdict = document.getElementById('verdictSelect').value;
  const note    = document.getElementById('agreementNote').value.trim();
  fetch('/influencer/orders/' + orderId + '/dispute/resolve', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN': csrf},
    body: JSON.stringify({verdict, resolution: note})
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload();
    else alert(d.message || 'خطا');
  });
}

// اسکرول به پایین
const box = document.getElementById('messagesBox');
if (box) box.scrollTop = box.scrollHeight;
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>