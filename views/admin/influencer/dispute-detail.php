<?php $title='جزئیات اختلاف'; $layout='admin'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-warning">gavel</i>
    داوری اختلاف — سفارش #<?= e($order->id ?? '') ?>
  </h4>
  <a href="<?= url('/admin/influencer/disputes') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="material-icons" style="font-size:15px;vertical-align:middle;">arrow_forward</i> بازگشت
  </a>
</div>

<?php
$statusLabels = ['open_peer'=>'گفت‌وگوی طرفین','resolved_peer'=>'حل دوستانه','escalated'=>'ارجاع به مدیر','resolved_admin'=>'رأی مدیر صادر شد','closed'=>'بسته'];
$isResolved = in_array($dispute->status ?? '', ['resolved_peer','resolved_admin','closed']);
?>

<div class="row mt-3 g-3">
  <!-- اطلاعات طرفین -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="card-title mb-0">اطلاعات پرونده</h6></div>
      <div class="card-body small">
        <div class="mb-2">
          <span class="text-muted">وضعیت اختلاف:</span>
          <span class="badge bg-warning text-dark ms-1">
            <?= $statusLabels[$dispute->status ?? ''] ?? ($dispute->status ?? '—') ?>
          </span>
        </div>
        <div class="mb-2">
          <span class="text-muted">تبلیغ‌دهنده:</span>
          <strong><?= e($dispute->customer_name ?? '—') ?></strong>
        </div>
        <div class="mb-2">
          <span class="text-muted">اینفلوئنسر:</span>
          <strong>@<?= e($dispute->influencer_username ?? $dispute->influencer_name ?? '—') ?></strong>
        </div>
        <hr class="my-2">
        <div class="mb-2">
          <span class="text-muted">نوع سفارش:</span>
          <strong><?= $order->order_type === 'story' ? 'استوری' : 'پست' ?></strong>
        </div>
        <div class="mb-2">
          <span class="text-muted">مبلغ:</span>
          <strong class="text-success"><?= number_format($order->price ?? 0) ?></strong>
        </div>
        <div class="mb-2">
          <span class="text-muted">درآمد اینفلوئنسر:</span>
          <strong><?= number_format($order->influencer_earning ?? 0) ?></strong>
        </div>
        <?php if(!empty($order->proof_link)): ?>
        <hr class="my-2">
        <div>
          <a href="<?= e($order->proof_link) ?>" target="_blank" class="btn btn-outline-info btn-sm w-100">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">open_in_new</i>
            مشاهده مدرک اینفلوئنسر
          </a>
        </div>
        <?php endif; ?>
        <?php if(!empty($order->proof_notes)): ?>
        <div class="mt-2 text-muted border-top pt-2">
          <strong>توضیح مدرک:</strong><br>
          <?= e($order->proof_notes) ?>
        </div>
        <?php endif; ?>
        <?php if(!empty($dispute->reason)): ?>
        <hr class="my-2">
        <div><strong class="text-danger">دلیل اعتراض:</strong><br>
          <span class="text-muted"><?= e($dispute->reason) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- پیام‌ها -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header"><h6 class="card-title mb-0">گفت‌وگوی طرفین</h6></div>
      <div class="card-body p-3" style="max-height:350px;overflow-y:auto;" id="msgBox">
        <?php if(empty($messages)): ?>
          <p class="text-muted text-center small">پیامی وجود ندارد.</p>
        <?php else: ?>
          <?php foreach($messages as $m):
            $roleColor = $m->role === 'customer' ? 'info' : ($m->role === 'influencer' ? 'primary' : 'warning');
            $roleLabel = $m->role === 'customer' ? 'تبلیغ‌دهنده' : ($m->role === 'influencer' ? 'اینفلوئنسر' : 'مدیر');
          ?>
          <div class="mb-3">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="badge bg-<?= $roleColor ?>"><?= $roleLabel ?></span>
              <span class="small fw-bold"><?= e($m->sender_name ?? '—') ?></span>
              <span class="text-muted" style="font-size:11px;"><?= e(substr($m->created_at ?? '', 0, 16)) ?></span>
            </div>
            <div class="bg-light rounded p-2 small" style="white-space:pre-wrap;"><?= e($m->message) ?></div>
            <?php if(!empty($m->attachment)): ?>
              <div class="mt-1">
                <a href="<?= e($m->attachment) ?>" target="_blank" class="small text-primary">
                  <i class="material-icons" style="font-size:12px;vertical-align:middle;">attach_file</i> پیوست
                </a>
              </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- فرم رأی مدیر -->
    <?php if(!$isResolved && ($dispute->status ?? '') === 'escalated'): ?>
    <div class="card mt-3 border-danger">
      <div class="card-header bg-danger bg-opacity-10">
        <h6 class="card-title mb-0 text-danger">
          <i class="material-icons" style="font-size:16px;vertical-align:middle;">gavel</i>
          صدور رأی نهایی
        </h6>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">رأی <span class="text-danger">*</span></label>
            <div class="d-flex flex-column gap-2">
              <label class="d-flex align-items-center gap-2 border rounded p-2 cursor-pointer">
                <input type="radio" name="verdict" value="favor_influencer" id="v1">
                <div>
                  <div class="fw-bold text-success">به نفع اینفلوئنسر</div>
                  <div class="small text-muted">مبلغ به اینفلوئنسر پرداخت می‌شود</div>
                </div>
              </label>
              <label class="d-flex align-items-center gap-2 border rounded p-2 cursor-pointer">
                <input type="radio" name="verdict" value="favor_customer" id="v2">
                <div>
                  <div class="fw-bold text-danger">به نفع تبلیغ‌دهنده</div>
                  <div class="small text-muted">مبلغ به تبلیغ‌دهنده بازگشت می‌یابد</div>
                </div>
              </label>
              <label class="d-flex align-items-center gap-2 border rounded p-2 cursor-pointer">
                <input type="radio" name="verdict" value="partial" id="v3">
                <div>
                  <div class="fw-bold text-warning">تسویه جزئی</div>
                  <div class="small text-muted">بخشی بازگشت، بخشی به اینفلوئنسر</div>
                </div>
              </label>
            </div>
          </div>
          <div class="col-md-6">
            <div id="partialGroup" style="display:none;" class="mb-3">
              <label class="form-label">درصد بازگشت به تبلیغ‌دهنده</label>
              <div class="input-group">
                <input type="number" id="refundPercent" class="form-control"
                       min="0" max="100" value="50">
                <span class="input-group-text">%</span>
              </div>
            </div>
            <label class="form-label fw-bold">توضیح رأی <span class="text-danger">*</span></label>
            <textarea id="verdictNote" class="form-control" rows="4"
                      placeholder="دلیل رأی را شرح دهید..."></textarea>
            <button class="btn btn-danger w-100 mt-3" onclick="submitVerdict()">
              <i class="material-icons" style="font-size:16px;vertical-align:middle;">gavel</i>
              صدور رأی نهایی
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php elseif($isResolved): ?>
    <div class="alert alert-success mt-3">
      <strong>رأی صادر شد:</strong>
      <?php
        $vl = ['favor_influencer'=>'به نفع اینفلوئنسر','favor_customer'=>'به نفع تبلیغ‌دهنده','partial'=>'تسویه جزئی'];
        echo e($vl[$dispute->admin_verdict ?? ''] ?? '—');
      ?>
      <?php if(!empty($dispute->admin_verdict_note)): ?>
        <div class="mt-1 small"><?= e($dispute->admin_verdict_note) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
document.querySelectorAll('input[name="verdict"]').forEach(r => {
  r.addEventListener('change', function() {
    document.getElementById('partialGroup').style.display =
      this.value === 'partial' ? 'block' : 'none';
  });
});

function submitVerdict() {
  const verdict = document.querySelector('input[name="verdict"]:checked')?.value;
  if (!verdict) { alert('رأی را انتخاب کنید.'); return; }
  const note = document.getElementById('verdictNote').value.trim();
  if (!note) { alert('توضیح رأی الزامی است.'); return; }
  const refundPercent = verdict === 'partial'
    ? parseFloat(document.getElementById('refundPercent').value) : 0;

  if (!confirm('آیا از صدور این رأی اطمینان دارید؟ این عملیات قابل بازگشت نیست.')) return;

  fetch('<?= url('/admin/influencer/disputes/' . (int)($dispute->id ?? 0) . '/resolve') ?>', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},
    body: JSON.stringify({dispute_id: <?= (int)($dispute->id ?? 0) ?>, verdict, note, refund_percent: refundPercent})
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload();
    else alert(d.message || 'خطا');
  });
}

// اسکرول به آخر پیام‌ها
const box = document.getElementById('msgBox');
if (box) box.scrollTop = box.scrollHeight;
</script>

<?php $content=ob_get_clean(); include view_path('layouts.'.$layout); ?>
