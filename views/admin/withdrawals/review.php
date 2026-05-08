<?php
$title = 'بررسی درخواست برداشت';
$layout = 'admin';
ob_start();
$withdrawal = $withdrawal ?? null;
$user = $user ?? null;
$card = $card ?? null;
$stMap = ['pending'=>'badge-warning','processing'=>'badge-info','completed'=>'badge-success','rejected'=>'badge-danger'];
$stLbl = ['pending'=>'در انتظار','processing'=>'پردازش','completed'=>'تکمیل','rejected'=>'رد شده'];
$s = $withdrawal->status ?? 'pending';
$kyc = $user->kyc_status ?? 'none';
?>

<!-- ══ PAGE HEADER ══ -->
<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--orange"><i class="material-icons">account_balance_wallet</i></div>
    <div>
      <h1 class="bx-page-header__title">بررسی درخواست برداشت <span class="bx-page-header__id">#<?= (int)($withdrawal->id ?? 0) ?></span></h1>
      <p class="bx-page-header__sub">
        ثبت شده: <?= to_jalali($withdrawal->created_at ?? '') ?>
        &nbsp;·&nbsp;
        <span class="bx-badge <?= $stMap[$s] ?? 'badge-muted' ?>"><?= $stLbl[$s] ?? $s ?></span>
      </p>
    </div>
  </div>
  <a href="<?= url('/admin/withdrawals') ?>" class="btn btn-secondary btn-sm">
    <i class="material-icons">arrow_forward</i>بازگشت به لیست
  </a>
</div>

<!-- ══ REVIEW LAYOUT ══ -->
<div class="bx-review-layout">

  <!-- LEFT COLUMN: User + Actions -->
  <div class="bx-review-layout__side">

    <!-- User Card -->
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">person</i>
        <h6>اطلاعات کاربر</h6>
      </div>
      <div class="bx-info-card__body">
        <div class="bx-user-profile">
          <?php $g = 'linear-gradient(135deg,#5b8af5,#7c3aed)'; ?>
          <div class="bx-user-profile__avatar" style="background:<?= $g ?>">
            <?= mb_substr($user->full_name ?? 'ک', 0, 1, 'UTF-8') ?>
          </div>
          <div class="bx-user-profile__info">
            <strong><?= e($user->full_name ?? '—') ?></strong>
            <small><?= e($user->email ?? '—') ?></small>
            <small><?= e($user->mobile ?? '—') ?></small>
          </div>
        </div>
        <div class="bx-divider"></div>
        <div class="bx-info-row">
          <span class="bx-info-row__label">وضعیت KYC</span>
          <span class="bx-badge <?= $kyc==='verified'?'badge-success':($kyc==='pending'?'badge-warning':'badge-danger') ?>">
            <?= ['verified'=>'تأیید شده','pending'=>'در انتظار','rejected'=>'رد شده','none'=>'ندارد'][$kyc] ?? $kyc ?>
          </span>
        </div>
        <div class="bx-info-row">
          <span class="bx-info-row__label">سطح کاربر</span>
          <span class="bx-badge badge-muted"><?= e($user->level ?? '—') ?></span>
        </div>
        <div class="bx-divider"></div>
        <a href="<?= url('/admin/users/edit/' . (int)($user->id ?? 0)) ?>" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center">
          <i class="material-icons">open_in_new</i>مشاهده پروفایل کامل
        </a>
      </div>
    </div>

    <!-- Action Card -->
    <?php if (($withdrawal->status ?? '') === 'pending'): ?>
    <div class="bx-info-card bx-info-card--action">
      <div class="bx-info-card__header">
        <i class="material-icons">admin_panel_settings</i>
        <h6>عملیات مدیریت</h6>
      </div>
      <div class="bx-info-card__body">
        <div class="bx-field-group">
          <label>شماره پیگیری پرداخت</label>
          <input type="text" id="trackingCode" class="bx-input" placeholder="کد پیگیری بانکی...">
          <small>برای تأیید الزامی است</small>
        </div>
        <div class="bx-action-col">
          <button class="btn btn-success" style="width:100%" onclick="doApprove()">
            <i class="material-icons">check_circle</i>تأیید و پرداخت
          </button>
          <button class="btn btn-danger" style="width:100%" onclick="doReject()">
            <i class="material-icons">cancel</i>رد درخواست
          </button>
        </div>
        <div id="rejectBox" style="display:none" class="bx-reject-box">
          <label>دلیل رد (برای کاربر ارسال می‌شود)</label>
          <textarea id="rejectReason" class="bx-input" rows="3" placeholder="دلیل رد را بنویسید..."></textarea>
          <button class="btn btn-danger" style="width:100%;margin-top:8px" onclick="confirmReject()">
            <i class="material-icons">send</i>ارسال و رد درخواست
          </button>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /side -->

  <!-- RIGHT COLUMN: Withdrawal Details -->
  <div class="bx-review-layout__main">

    <!-- Amount Banner -->
    <div class="bx-amount-banner bx-amount-banner--<?= $s === 'completed' ? 'green' : ($s === 'rejected' ? 'red' : 'orange') ?>">
      <div class="bx-amount-banner__icon"><i class="material-icons">payments</i></div>
      <div class="bx-amount-banner__content">
        <span class="bx-amount-banner__label">مبلغ درخواستی</span>
        <span class="bx-amount-banner__value"><?= number_format((float)($withdrawal->amount ?? 0)) ?> <em><?= strtoupper($withdrawal->currency ?? 'IRT') ?></em></span>
      </div>
      <div class="bx-amount-banner__status">
        <span class="bx-badge <?= $stMap[$s] ?? 'badge-muted' ?>"><?= $stLbl[$s] ?? $s ?></span>
      </div>
    </div>

    <!-- Details Grid -->
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">receipt_long</i>
        <h6>جزئیات درخواست</h6>
      </div>
      <div class="bx-info-card__body bx-info-card__body--p0">
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">شناسه درخواست</span>
          <code>#<?= (int)($withdrawal->id ?? 0) ?></code>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">ارز</span>
          <span class="bx-badge badge-primary"><?= strtoupper(e($withdrawal->currency ?? '—')) ?></span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">تاریخ ثبت</span>
          <span><?= to_jalali($withdrawal->created_at ?? '') ?></span>
        </div>
        <?php if (!empty($withdrawal->updated_at)): ?>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">آخرین بروزرسانی</span>
          <span><?= to_jalali($withdrawal->updated_at) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($card)): ?>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">شماره کارت</span>
          <div>
            <span class="bx-mono-chip"><?= e($card->card_number ?? '—') ?></span>
            <?php if (!empty($card->bank_name)): ?>
            <small style="display:block;margin-top:3px;color:var(--fg-muted)"><?= e($card->bank_name) ?></small>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($withdrawal->wallet_address)): ?>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">آدرس کیف پول</span>
          <span class="bx-mono-chip bx-mono-chip--small"><?= e($withdrawal->wallet_address) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($withdrawal->tracking_code)): ?>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">کد پیگیری</span>
          <code><?= e($withdrawal->tracking_code) ?></code>
        </div>
        <?php endif; ?>
        <?php if (!empty($withdrawal->reject_reason)): ?>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">دلیل رد</span>
          <span class="bx-badge badge-danger"><?= e($withdrawal->reject_reason) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($withdrawal->notes)): ?>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">یادداشت</span>
          <span><?= e($withdrawal->notes) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /main -->

</div><!-- /review-layout -->

<script>
const wId = <?= (int)($withdrawal->id ?? 0) ?>;
const csrf = '<?= csrf_token() ?>';

function doApprove() {
  const tracking = document.getElementById('trackingCode').value.trim();
  if (!tracking) { notyf.error('لطفاً شماره پیگیری را وارد کنید'); return; }
  Swal.fire({ title:'تأیید برداشت', text:'پرداخت با کد پیگیری «'+tracking+'» ثبت می‌شود.', icon:'question', showCancelButton:true, confirmButtonText:'✅ تأیید', cancelButtonText:'انصراف', confirmButtonColor:'#0ECB81' })
  .then(r => {
    if (!r.isConfirmed) return;
    fetch(`<?= url('/admin/withdrawals') ?>/${wId}/approve`, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify({tracking_code:tracking}) })
    .then(r=>r.json()).then(res => { if(res.success){location.href='<?= url('/admin/withdrawals') ?>';}else{notyf.error(res.message||'خطا');} });
  });
}

function doReject() {
  document.getElementById('rejectBox').style.display = 'block';
}

function confirmReject() {
  const reason = document.getElementById('rejectReason').value.trim();
  Swal.fire({ title:'رد درخواست', text:'این عملیات غیرقابل بازگشت است.', icon:'warning', showCancelButton:true, confirmButtonText:'⛔ رد شود', cancelButtonText:'انصراف', confirmButtonColor:'#F6465D' })
  .then(r => {
    if (!r.isConfirmed) return;
    fetch(`<?= url('/admin/withdrawals') ?>/${wId}/reject`, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify({reason}) })
    .then(r=>r.json()).then(res => { if(res.success){location.href='<?= url('/admin/withdrawals') ?>';}else{notyf.error(res.message||'خطا');} });
  });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
