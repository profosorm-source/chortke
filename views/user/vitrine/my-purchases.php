<?php $layout = 'user'; ob_start();
$statusColors = [
  'pending'=>'warning','active'=>'success','in_escrow'=>'primary',
  'disputed'=>'danger','sold'=>'secondary','cancelled'=>'secondary','rejected'=>'danger',
];
?>

<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-primary align-middle">shopping_cart</span>
      خریدهای من — ویترین
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">تاریخچه خریدها و معاملات فعال شما</p>
  </div>
  <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">storefront</span> بازار ویترین
  </a>
</div>

<?php if (empty($listings)): ?>
<div class="text-center py-5 mt-4">
  <span class="material-icons text-muted" style="font-size:64px;">shopping_cart</span>
  <p class="text-muted mt-2">هنوز خریدی انجام نداده‌اید.</p>
  <a href="<?= url('/vitrine') ?>" class="btn btn-primary btn-sm">مشاهده آگهی‌های فروش</a>
</div>
<?php else: ?>

<div class="row g-3 mt-2">
  <?php foreach ($listings as $l): ?>
  <?php
    $sc = $statusColors[$l->status] ?? 'secondary';
    $sl = $statuses[$l->status]     ?? $l->status;
    $cl = $categories[$l->category] ?? $l->category;
    $amount = $l->offer_price_usdt ?? $l->price_usdt;
  ?>
  <div class="col-12 col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <span class="badge bg-primary bg-opacity-10 text-primary"><?= e($cl) ?></span>
          <span class="badge bg-<?= $sc ?>"><?= e($sl) ?></span>
        </div>
        <h6 class="fw-bold mb-1">
          <a href="<?= url('/vitrine/' . $l->id) ?>" class="text-decoration-none text-dark">
            <?= e(mb_substr($l->title, 0, 60)) ?>
          </a>
        </h6>
        <div class="small text-muted mb-2">فروشنده: <?= e($l->seller_name ?? '—') ?></div>

        <div class="d-flex justify-content-between align-items-center">
          <span class="fw-bold text-success"><?= number_format((float)$amount, 2) ?> USDT</span>
          <span class="small text-muted"><?= e(substr($l->updated_at ?? '', 0, 10)) ?></span>
        </div>

        <?php if ($l->status === 'in_escrow'): ?>
        <div class="alert alert-primary small mt-2 mb-1 py-1 px-2">
          <span class="material-icons" style="font-size:13px;vertical-align:middle;">lock</span>
          پول در escrow — منتظر تایید شما
        </div>
        <div class="d-flex gap-1 mt-2">
          <a href="<?= url('/vitrine/' . $l->id) ?>" class="btn btn-success btn-sm flex-fill">
            تایید دریافت
          </a>
          <a href="<?= url('/vitrine/' . $l->id) ?>" class="btn btn-outline-danger btn-sm">
            <span class="material-icons" style="font-size:14px;">gavel</span>
          </a>
        </div>
        <?php elseif ($l->status === 'sold'): ?>
        <div class="alert alert-secondary small mt-2 mb-0 py-1 px-2">
          <span class="material-icons" style="font-size:13px;vertical-align:middle;">done_all</span>
          معامله تکمیل شده
          <?= $l->auto_confirmed ? '(تایید خودکار)' : '' ?>
        </div>
        <?php elseif ($l->status === 'disputed'): ?>
        <div class="alert alert-danger small mt-2 mb-0 py-1 px-2">
          <span class="material-icons" style="font-size:13px;vertical-align:middle;">gavel</span>
          اختلاف در حال بررسی
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
