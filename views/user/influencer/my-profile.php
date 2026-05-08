<?php $layout='user'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-primary">account_circle</i> پروفایل اینفلوئنسر
  </h4>
  <div class="d-flex gap-2">
    <?php if(!$profile): ?>
      <a href="<?= url('/influencer/register') ?>" class="btn btn-primary btn-sm">
        <i class="material-icons" style="font-size:15px;vertical-align:middle;">add</i> ثبت پیج
      </a>
    <?php else: ?>
      <a href="<?= url('/influencer/register') ?>" class="btn btn-outline-secondary btn-sm">ویرایش</a>
      <a href="<?= url('/influencer/orders') ?>" class="btn btn-outline-primary btn-sm">سفارش‌های دریافتی</a>
    <?php endif; ?>
  </div>
</div>

<?php if(!$profile): ?>
  <div class="card mt-4">
    <div class="card-body text-center py-5">
      <i class="material-icons text-muted" style="font-size:64px;">account_circle</i>
      <h5 class="mt-3">پیجی ثبت نکرده‌اید</h5>
      <p class="text-muted small">پیج اینستاگرام یا تلگرام خود را ثبت کنید و سفارش تبلیغ بگیرید.</p>
      <a href="<?= url('/influencer/register') ?>" class="btn btn-primary mt-2">ثبت پیج</a>
    </div>
  </div>

<?php else: ?>

<?php
$statusColors = [
  'pending'              => 'warning',
  'submitted'            => 'info',
  'pending_admin_review' => 'info',
  'expired'              => 'warning',
  'verified'             => 'success',
  'rejected'             => 'danger',
  'suspended'            => 'dark',
];
$statusLabels = [
  'pending'              => 'در انتظار ثبت کد تایید',
  'submitted'            => 'در انتظار تایید مدیر',
  'pending_admin_review' => 'در انتظار تایید مدیر',
  'expired'              => 'کد تایید منقضی شده',
  'verified'             => 'تایید شده ✓',
  'rejected'             => 'رد شده',
  'suspended'            => 'تعلیق شده',
];
$verificationState = $verificationStatus['status'] ?? null;
$st = $verificationState ?: ($profile->status ?? 'pending');
$displayCode = $verificationCode ?? $profile->verification_code ?? '—';
?>

<!-- کارت اصلی پروفایل -->
<div class="card mt-3">
  <div class="card-body">
    <div class="d-flex gap-3 align-items-center">
      <?php if(!empty($profile->profile_image)): ?>
        <img src="<?= e($profile->profile_image) ?>" class="rounded-circle"
             style="width:70px;height:70px;object-fit:cover;">
      <?php else: ?>
        <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold"
             style="width:70px;height:70px;font-size:26px;">
          <?= mb_strtoupper(mb_substr($profile->username ?? 'U', 0, 1)) ?>
        </div>
      <?php endif; ?>
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <h5 class="mb-0">@<?= e($profile->username) ?></h5>
          <span class="badge bg-<?= $statusColors[$st] ?? 'secondary' ?>">
            <?= $statusLabels[$st] ?? $st ?>
          </span>
          <?php if($st === 'verified'): ?>
            <i class="material-icons text-success" style="font-size:20px;" title="تایید شده">verified</i>
          <?php endif; ?>
        </div>
        <div class="text-muted small mt-1">
          <?= number_format($profile->follower_count ?? 0) ?> فالوور
          <?php if(!empty($profile->category)): ?> · <?= e($profile->category) ?><?php endif; ?>
        </div>
        <div class="mt-1">
          <a href="<?= e($profile->page_url ?? '#') ?>" target="_blank" class="text-muted small">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">link</i>
            مشاهده پیج
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if($st === 'rejected'): ?>
<!-- رد شده -->
<div class="alert alert-danger mt-3">
  <strong>پیج رد شد:</strong> <?= e($profile->rejection_reason ?? 'مطابق شرایط نیست') ?>
  <div class="mt-2">
    <a href="<?= url('/influencer/register') ?>" class="btn btn-sm btn-outline-danger">ویرایش و ارسال مجدد</a>
  </div>
</div>

<?php elseif($st === 'pending'): ?>
<!-- مرحله ۱: ثبت کد تایید -->
<div class="card mt-3 border-warning">
  <div class="card-header bg-warning bg-opacity-10">
    <h6 class="card-title mb-0">
      <i class="material-icons text-warning" style="font-size:18px;vertical-align:middle;">pending</i>
      مرحله ۱: انتشار کد تایید مالکیت
    </h6>
  </div>
  <div class="card-body">
    <p class="mb-3 small">برای تایید مالکیت پیج، باید یک پست یا استوری موقت با کد زیر در پیجتان منتشر کنید:</p>
    <div class="d-flex align-items-center gap-2 mb-3">
      <div class="bg-light border rounded px-3 py-2 fw-bold font-monospace fs-5 text-primary">
        <?= e($displayCode) ?>
      </div>
      <button class="btn btn-outline-secondary btn-sm" onclick="copyCode('<?= e($displayCode) ?>')">
        <i class="material-icons" style="font-size:16px;">content_copy</i>
      </button>
    </div>
    <ol class="small text-muted mb-3">
      <li>کد بالا را در یک پست یا استوری پیجتان منتشر کنید.</li>
      <li>لینک آن پست را در کادر زیر وارد کنید.</li>
      <li>بعد از تایید مدیر می‌توانید پست را حذف کنید.</li>
    </ol>
    <form action="<?= url('/influencer/verify') ?>" method="POST" class="d-flex gap-2">
      <?= csrf_field() ?>
      <input type="url" name="post_url" class="form-control form-control-sm"
             placeholder="https://www.instagram.com/p/..." required>
      <button type="submit" class="btn btn-warning btn-sm text-white" style="white-space:nowrap;">
        ثبت لینک پست
      </button>
    </form>
  </div>
</div>

<?php elseif(in_array($st, ['submitted','pending_admin_review'], true)): ?>
<!-- مرحله ۲: در انتظار مدیر -->
<div class="card mt-3 border-info">
  <div class="card-body d-flex align-items-center gap-3">
    <i class="material-icons text-info" style="font-size:40px;">hourglass_top</i>
    <div>
      <strong>لینک پست شما ثبت شد.</strong>
      <p class="text-muted small mb-1">مدیر پیج شما را بررسی می‌کند. معمولاً تا ۲۴ ساعت طول می‌کشد.</p>
      <?php if(!empty($profile->verification_post_url)): ?>
        <a href="<?= e($profile->verification_post_url) ?>" target="_blank" class="small text-muted">
          <i class="material-icons" style="font-size:13px;vertical-align:middle;">link</i>
          مشاهده پست ثبت‌شده
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php elseif($st === 'expired'): ?>
<div class="alert alert-warning mt-3">
  <strong>کد تایید منقضی شده است.</strong>
  <p class="mb-0">کد جدید ساخته شد. آن را در یک پست یا استوری قرار دهید و دوباره لینک را ارسال کنید.</p>
</div>

<?php elseif($st === 'verified'): ?>
<!-- تایید شده - آمار -->
<?php if($stats): ?>
<div class="row mt-3 g-3">
  <div class="col-6 col-md-3">
    <div class="card text-center h-100">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-primary"><?= number_format($stats->total_orders) ?></div>
        <div class="small text-muted">کل سفارش‌ها</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center h-100">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-success"><?= $stats->completion_rate ?>%</div>
        <div class="small text-muted">نرخ تکمیل</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center h-100">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-<?= $stats->grade_color ?>"><?= e($stats->grade) ?></div>
        <div class="small text-muted">رتبه: <?= e($stats->grade_label) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center h-100">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-<?= $stats->dispute_rate > 20 ? 'danger' : 'muted' ?>">
          <?= $stats->dispute_rate ?>%
        </div>
        <div class="small text-muted">نرخ اختلاف</div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- تعرفه‌ها -->
<div class="card mt-3">
  <div class="card-header"><h6 class="card-title mb-0">تعرفه‌های فعال</h6></div>
  <div class="card-body">
    <div class="row g-2 small">
      <?php if($profile->story_price_24h > 0): ?>
        <div class="col-6 col-md-3">
          <div class="bg-light rounded p-2 text-center">
            <div class="fw-bold text-success"><?= number_format($profile->story_price_24h) ?></div>
            <div class="text-muted">استوری ۲۴ساعته</div>
          </div>
        </div>
      <?php endif; ?>
      <?php if($profile->post_price_24h > 0): ?>
        <div class="col-6 col-md-3">
          <div class="bg-light rounded p-2 text-center">
            <div class="fw-bold text-success"><?= number_format($profile->post_price_24h) ?></div>
            <div class="text-muted">پست ۲۴ساعته</div>
          </div>
        </div>
      <?php endif; ?>
      <?php if($profile->post_price_48h > 0): ?>
        <div class="col-6 col-md-3">
          <div class="bg-light rounded p-2 text-center">
            <div class="fw-bold text-success"><?= number_format($profile->post_price_48h) ?></div>
            <div class="text-muted">پست ۴۸ساعته</div>
          </div>
        </div>
      <?php endif; ?>
      <?php if($profile->post_price_72h > 0): ?>
        <div class="col-6 col-md-3">
          <div class="bg-light rounded p-2 text-center">
            <div class="fw-bold text-success"><?= number_format($profile->post_price_72h) ?></div>
            <div class="text-muted">پست ۷۲ساعته</div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- آخرین سفارش‌ها -->
<?php if(!empty($orders)): ?>
<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="card-title mb-0">آخرین سفارش‌ها</h6>
    <a href="<?= url('/influencer/orders') ?>" class="btn btn-outline-primary btn-sm">همه</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead class="table-light">
          <tr><th>#</th><th>نوع</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr>
        </thead>
        <tbody>
          <?php foreach($orders as $o):
            $sl = $statusLabels ?? [];
            $sc = ['completed'=>'success','paid'=>'info','accepted'=>'primary','proof_submitted'=>'warning','awaiting_buyer_check'=>'warning','rejected_by_influencer'=>'danger','refunded'=>'danger','peer_resolution'=>'danger','escalated_to_admin'=>'danger'];
          ?>
          <tr>
            <td><?= e($o->id) ?></td>
            <td><?= $o->order_type === 'story' ? 'استوری' : 'پست' ?></td>
            <td class="text-success fw-bold"><?= number_format($o->influencer_earning ?? 0) ?></td>
            <td><span class="badge bg-<?= $sc[$o->status] ?? 'secondary' ?>"><?= e($o->status) ?></span></td>
            <td><?= e(substr($o->created_at ?? '', 0, 10)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; // end verified ?>

<?php endif; // end profile exists ?>

<script>
function copyCode(code) {
  navigator.clipboard.writeText(code).then(() => {
    const btn = event.currentTarget;
    btn.innerHTML = '<i class="material-icons" style="font-size:16px;">check</i>';
    setTimeout(() => btn.innerHTML = '<i class="material-icons" style="font-size:16px;">content_copy</i>', 2000);
  });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>