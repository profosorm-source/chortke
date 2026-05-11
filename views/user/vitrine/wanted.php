<?php $layout = 'user'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-info align-middle">search</span>
      ویترین — خریداران (متقاضیان)
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">
      کاربرانی که دنبال چیزی می‌گردند — اگر محصول مناسب دارید، پیشنهاد بدهید
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">storefront</span> آگهی‌های فروش
    </a>
    <a href="<?= url('/vitrine/wanted/create') ?>" class="btn btn-info btn-sm text-white">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> ثبت درخواست خرید
    </a>
  </div>
</div>

<!-- فیلتر -->
<div class="card mt-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <select name="category" class="form-select form-select-sm">
          <option value="">همه دسته‌ها</option>
          <?php foreach ($categories as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= ($filters['category'] ?? '') === $k ? 'selected' : '' ?>>
            <?= e($v) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <select name="platform" class="form-select form-select-sm">
          <?php foreach ($platforms as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= ($filters['platform'] ?? '') === $k ? 'selected' : '' ?>>
            <?= e($v) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-9 col-md-4">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
      </div>
      <div class="col-3 col-md-2">
        <button class="btn btn-primary btn-sm w-100">جستجو</button>
      </div>
    </form>
  </div>
</div>

<?php if (empty($listings)): ?>
<div class="text-center py-5 mt-2">
  <span class="material-icons text-muted" style="font-size:64px;">search</span>
  <p class="text-muted mt-2">هیچ درخواست خریدی یافت نشد.</p>
</div>
<?php else: ?>

<div class="row g-3 mt-1">
  <?php foreach ($listings as $l): ?>
  <?php
    $catIcons = ['page'=>'person','channel'=>'campaign','group'=>'group',
                 'vps'=>'dns','vpn'=>'vpn_lock','website'=>'language','other'=>'search'];
    $icon  = $catIcons[$l->category]   ?? 'search';
    $cat   = $categories[$l->category] ?? $l->category;
    $plat  = $platforms[$l->platform]  ?? '';
  ?>
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100 border-info border-opacity-25">
      <div class="card-body">
        <!-- هدر -->
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="d-flex gap-1 flex-wrap">
            <span class="badge bg-info bg-opacity-15 text-info">
              <span class="material-icons" style="font-size:11px;vertical-align:middle;"><?= $icon ?></span>
              <?= e($cat) ?>
            </span>
            <?php if ($plat): ?>
            <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= e($plat) ?></span>
            <?php endif; ?>
          </div>
          <span class="badge bg-info text-white" style="font-size:10px;">خریدار</span>
        </div>

        <!-- عنوان -->
        <h6 class="fw-bold mb-1"><?= e(mb_substr($l->title, 0, 60)) ?></h6>

        <!-- توضیحات -->
        <p class="small text-secondary mb-2" style="
          display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
          <?= e(mb_substr($l->description, 0, 140)) ?>
        </p>

        <!-- بودجه -->
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="small text-muted">بودجه: </span>
            <span class="fw-bold text-info"><?= number_format((float)$l->price_usdt, 2) ?> USDT</span>
          </div>
          <div class="text-muted small">
            <span class="material-icons" style="font-size:12px;vertical-align:middle;">person</span>
            <?= e($l->seller_name ?? '—') ?>
            <?php if (($l->seller_kyc ?? '') === 'verified'): ?>
            <span class="material-icons text-success" style="font-size:12px;vertical-align:middle;" title="KYC">verified</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- دکمه -->
        <a href="<?= url('/vitrine/' . $l->id) ?>" class="btn btn-sm btn-outline-info w-100 mt-2">
          مشاهده و ارسال پیشنهاد
        </a>
      </div>
      <div class="card-footer bg-transparent py-1 px-3">
        <span class="small text-muted">
          <?= e(substr($l->created_at ?? '', 0, 10)) ?>
        </span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (($page ?? 1) > 1): ?>
<nav class="d-flex justify-content-center mt-4">
  <ul class="pagination pagination-sm mb-0">
    <?php for ($i = 1; $i <= ($pages ?? 1); $i++): ?>
    <li class="page-item <?= $i === ($page ?? 1) ? 'active' : '' ?>">
      <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>">
        <?= $i ?>
      </a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
