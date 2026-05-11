<?php $layout = 'user'; ob_start();
$statusColors = [
  'pending'=>'warning','active'=>'success','in_escrow'=>'primary',
  'disputed'=>'danger','sold'=>'secondary','cancelled'=>'secondary','rejected'=>'danger',
];
$catIcons = [
  'page'=>'person','channel'=>'campaign','group'=>'group',
  'vps'=>'dns','vpn'=>'vpn_lock','website'=>'language','other'=>'sell'
];
?>

<div class="content-header d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-primary align-middle">list_alt</span>
      آگهی‌های من — ویترین
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">مدیریت آگهی‌های فروش و درخواست‌های خرید شما</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= url('/vitrine/sell/create') ?>" class="btn btn-primary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> ثبت آگهی فروش
    </a>
    <a href="<?= url('/vitrine/wanted/create') ?>" class="btn btn-outline-primary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">search</span> ثبت درخواست خرید
    </a>
  </div>
</div>

<?php if (empty($listings)): ?>
<div class="text-center py-5 mt-4">
  <span class="material-icons text-muted" style="font-size:64px;">storefront</span>
  <p class="text-muted mt-2">هنوز آگهی‌ای ثبت نکرده‌اید.</p>
  <div class="d-flex justify-content-center gap-2">
    <a href="<?= url('/vitrine/sell/create') ?>" class="btn btn-primary btn-sm">ثبت آگهی فروش</a>
    <a href="<?= url('/vitrine/wanted/create') ?>" class="btn btn-outline-primary btn-sm">درخواست خرید</a>
  </div>
</div>
<?php else: ?>

<div class="card mt-3">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>نوع</th>
            <th>دسته / پلتفرم</th>
            <th>عنوان</th>
            <th>قیمت</th>
            <th>وضعیت</th>
            <th>تاریخ ثبت</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listings as $l): ?>
          <?php
            $sc = $statusColors[$l->status]  ?? 'secondary';
            $ic = $catIcons[$l->category]    ?? 'sell';
            $cl = $categories[$l->category]  ?? $l->category;
            $sl = $statuses[$l->status]      ?? $l->status;
            $isSell = $l->listing_type === 'sell';
          ?>
          <tr>
            <td class="text-muted small"><?= e($l->id) ?></td>
            <td>
              <span class="badge bg-<?= $isSell ? 'success' : 'info' ?> bg-opacity-15 text-<?= $isSell ? 'success' : 'info' ?>">
                <?= $isSell ? 'فروش' : 'خرید' ?>
              </span>
            </td>
            <td>
              <div class="d-flex flex-column gap-1">
                <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:11px;">
                  <span class="material-icons" style="font-size:11px;vertical-align:middle;"><?= $ic ?></span>
                  <?= e($cl) ?>
                </span>
                <?php if (!empty($l->platform)): ?>
                <span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:10px;">
                  <?= e($l->platform) ?>
                </span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <a href="<?= url('/vitrine/' . $l->id) ?>" class="fw-medium text-decoration-none text-dark">
                <?= e(mb_substr($l->title, 0, 50)) ?><?= mb_strlen($l->title) > 50 ? '...' : '' ?>
              </a>
              <?php if ($l->status === 'in_escrow' && $l->buyer_name): ?>
              <div class="small text-muted">خریدار: <?= e($l->buyer_name) ?></div>
              <?php endif; ?>
            </td>
            <td class="fw-bold text-success">
              <?= number_format((float)$l->price_usdt, 2) ?> USDT
              <?php if ($l->offer_price_usdt && $l->offer_price_usdt != $l->price_usdt): ?>
              <div class="small text-warning">(توافقی: <?= number_format((float)$l->offer_price_usdt, 2) ?>)</div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-<?= $sc ?>"><?= e($sl) ?></span>
              <?php if ($l->status === 'in_escrow' && $l->escrow_deadline): ?>
              <div class="small text-muted mt-1">
                <span class="material-icons" style="font-size:11px;vertical-align:middle;">schedule</span>
                <?= e(substr($l->escrow_deadline, 0, 10)) ?>
              </div>
              <?php endif; ?>
              <?php if ($l->status === 'rejected' && $l->rejection_reason): ?>
              <div class="small text-danger mt-1" title="<?= e($l->rejection_reason) ?>">
                <span class="material-icons" style="font-size:11px;vertical-align:middle;">error</span>
                <?= e(mb_substr($l->rejection_reason, 0, 30)) ?>...
              </div>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= e(substr($l->created_at ?? '', 0, 10)) ?></td>
            <td>
              <a href="<?= url('/vitrine/' . $l->id) ?>"
                 class="btn btn-outline-primary btn-sm"
                 title="مشاهده">
                <span class="material-icons" style="font-size:15px;">visibility</span>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
