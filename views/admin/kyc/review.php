<?php
$title = 'بررسی KYC #' . ($verification->id ?? '');
$layout = 'admin';
ob_start();
$v = $verification ?? null;
$u = $user ?? null;
$docTypes = ['national_id'=>'کارت ملی','passport'=>'پاسپورت','driving_license'=>'گواهینامه'];
$stMap    = ['pending'=>'badge-warning','under_review'=>'badge-info','verified'=>'badge-success','rejected'=>'badge-danger'];
$stNames  = ['pending'=>'در انتظار','under_review'=>'در بررسی','verified'=>'تأیید شده','rejected'=>'رد شده'];
$s = $v->status ?? 'pending';
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--blue"><i class="material-icons">verified_user</i></div>
    <div>
      <h1 class="bx-page-header__title">بررسی احراز هویت <span class="bx-page-header__id">#<?= (int)($v->id ?? 0) ?></span></h1>
      <p class="bx-page-header__sub">
        <?= $docTypes[$v->document_type ?? ''] ?? '—' ?>
        &nbsp;·&nbsp; <?= to_jalali($v->created_at ?? '') ?>
        &nbsp;·&nbsp; <span class="bx-badge <?= $stMap[$s] ?? 'badge-muted' ?>"><?= $stNames[$s] ?? $s ?></span>
      </p>
    </div>
  </div>
  <a href="<?= url('/admin/kyc') ?>" class="btn btn-secondary btn-sm"><i class="material-icons">arrow_forward</i>بازگشت</a>
</div>

<div class="bx-review-layout">

  <!-- SIDEBAR -->
  <div class="bx-review-layout__side">

    <!-- User Info -->
    <div class="bx-info-card">
      <div class="bx-info-card__header"><i class="material-icons">person</i><h6>اطلاعات کاربر</h6></div>
      <div class="bx-info-card__body">
        <div class="bx-user-profile bx-user-profile--lg">
          <div class="bx-user-profile__avatar bx-user-profile__avatar--lg" style="background:linear-gradient(135deg,#5b8af5,#7c3aed)">
            <?= mb_substr($u->full_name ?? 'ک', 0, 1, 'UTF-8') ?>
          </div>
          <div class="bx-user-profile__info">
            <strong><?= e($u->full_name ?? '—') ?></strong>
            <small><?= e($u->email ?? '—') ?></small>
            <small><?= e($u->mobile ?? '—') ?></small>
          </div>
        </div>
        <div class="bx-divider"></div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">نام قانونی</span>
          <strong><?= e($v->legal_name ?? '—') ?></strong>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">کد ملی</span>
          <code dir="ltr"><?= e($v->national_code ?? '—') ?></code>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">نوع مدرک</span>
          <span class="bx-badge badge-muted"><?= $docTypes[$v->document_type ?? ''] ?? '—' ?></span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">تاریخ ارسال</span>
          <span><?= to_jalali($v->created_at ?? '') ?></span>
        </div>
      </div>
    </div>

    <!-- Action -->
    <?php if (in_array($s, ['pending','under_review'])): ?>
    <div class="bx-info-card bx-info-card--action">
      <div class="bx-info-card__header"><i class="material-icons">gavel</i><h6>تصمیم‌گیری</h6></div>
      <div class="bx-info-card__body">
        <p style="font-size:12px;color:var(--fg-muted);margin-bottom:16px">پس از بررسی مدارک زیر تصمیم خود را ثبت کنید.</p>
        <button class="btn btn-success" style="width:100%;margin-bottom:8px" onclick="doApprove()">
          <i class="material-icons">verified_user</i>تأیید احراز هویت
        </button>
        <button class="btn btn-danger" style="width:100%" onclick="showRejectBox()">
          <i class="material-icons">cancel</i>رد احراز هویت
        </button>
        <div id="rejectBox" style="display:none;margin-top:12px">
          <div class="bx-field-group">
            <label>دلیل رد</label>
            <textarea id="rejectReason" class="bx-input" rows="3" placeholder="دلیل رد برای کاربر..."></textarea>
          </div>
          <button class="btn btn-danger" style="width:100%" onclick="confirmReject()">
            <i class="material-icons">send</i>ثبت رد
          </button>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- MAIN: Documents -->
  <div class="bx-review-layout__main">

    <!-- Document Images -->
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">photo_library</i><h6>تصاویر مدارک</h6>
      </div>
      <div class="bx-info-card__body">
        <div class="bx-doc-grid">
          <?php
          $docs = [
            ['front_image', 'تصویر جلو مدرک', 'badge_filled'],
            ['back_image',  'تصویر پشت مدرک', 'badge'],
            ['selfie_image','سلفی با مدرک',    'face'],
          ];
          foreach ($docs as [$field, $label, $icon]):
            $imgPath = $v->$field ?? null;
          ?>
          <div class="bx-doc-item <?= $imgPath ? '' : 'bx-doc-item--empty' ?>">
            <div class="bx-doc-item__label">
              <i class="material-icons"><?= $icon ?></i><?= $label ?>
            </div>
            <?php if ($imgPath): ?>
            <a href="<?= url($imgPath) ?>" target="_blank" class="bx-doc-item__img-wrap">
              <img src="<?= url($imgPath) ?>" alt="<?= $label ?>" loading="lazy">
              <div class="bx-doc-item__overlay"><i class="material-icons">zoom_in</i></div>
            </a>
            <?php else: ?>
            <div class="bx-doc-item__placeholder">
              <i class="material-icons">image_not_supported</i>
              <span>بارگذاری نشده</span>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- History / Notes -->
    <?php if (!empty($v->admin_notes)): ?>
    <div class="bx-info-card">
      <div class="bx-info-card__header"><i class="material-icons">notes</i><h6>یادداشت مدیر</h6></div>
      <div class="bx-info-card__body">
        <p style="font-size:13px;color:var(--fg-secondary);line-height:1.8"><?= nl2br(e($v->admin_notes)) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($v->reject_reason)): ?>
    <div class="bx-alert bx-alert--red">
      <i class="material-icons">error</i>
      <div><strong>دلیل رد:</strong> <?= e($v->reject_reason) ?></div>
    </div>
    <?php endif; ?>

  </div>

</div>

<script>
const vId = <?= (int)($v->id ?? 0) ?>;
const csrf = '<?= csrf_token() ?>';
function doApprove() {
  Swal.fire({title:'تأیید احراز هویت',text:'مدارک این کاربر تأیید و وضعیت KYC به «تأیید شده» تغییر می‌کند.',icon:'question',showCancelButton:true,confirmButtonText:'✅ تأیید',cancelButtonText:'انصراف',confirmButtonColor:'#10b981'})
  .then(r => {
    if(!r.isConfirmed) return;
    fetch(`<?= url('/admin/kyc/') ?>${vId}/approve`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({_token:csrf})})
    .then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message||'تأیید شد');setTimeout(()=>location.href='<?= url('/admin/kyc') ?>',900);}else{notyf.error(d.message||'خطا');}});
  });
}
function showRejectBox(){ document.getElementById('rejectBox').style.display='block'; }
function confirmReject() {
  const reason = document.getElementById('rejectReason').value.trim();
  if(!reason){notyf.error('دلیل رد الزامی است');return;}
  Swal.fire({title:'رد احراز هویت',text:'این عملیات برگشت‌ناپذیر است.',icon:'warning',showCancelButton:true,confirmButtonText:'⛔ رد',cancelButtonText:'انصراف',confirmButtonColor:'#ef4444'})
  .then(r => {
    if(!r.isConfirmed) return;
    fetch(`<?= url('/admin/kyc/') ?>${vId}/reject`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({reason,_token:csrf})})
    .then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message||'رد شد');setTimeout(()=>location.href='<?= url('/admin/kyc') ?>',900);}else{notyf.error(d.message||'خطا');}});
  });
}
</script>

<?php $content = ob_get_clean(); require_once view_path('layouts.admin'); ?>
