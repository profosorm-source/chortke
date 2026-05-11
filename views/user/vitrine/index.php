<?php $layout = 'user'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-primary align-middle">storefront</span>
      ویترین — بازار دیجیتال
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">
      خرید و فروش متنی پیج، کانال، گروه، VPS، فیلترشکن، سایت و موارد مشابه — پرداخت امن با USDT
    </p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= url('/vitrine/wanted') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">search</span> خریداران
    </a>
    <a href="<?= url('/vitrine/my-listings') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">list_alt</span> آگهی‌های من
    </a>
    <a href="<?= url('/vitrine/my-purchases') ?>" class="btn btn-outline-info btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">shopping_cart</span> خریدهای من
    </a>
    <a href="<?= url('/vitrine/sell/create') ?>" class="btn btn-primary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> ثبت آگهی فروش
    </a>
  </div>
</div>

<!-- فیلترهای پیشرفته -->
<div class="card mt-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">دسته‌بندی</label>
        <select name="category" class="form-select form-select-sm">
          <option value="">همه دسته‌ها</option>
          <?php foreach ($categories as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($filters['category'] ?? '') === $k ? 'selected' : '' ?>>
              <?= e($v) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">پلتفرم</label>
        <select name="platform" class="form-select form-select-sm">
          <?php foreach ($platforms as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($filters['platform'] ?? '') === $k ? 'selected' : '' ?>>
              <?= e($v) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1">جستجو</label>
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="عنوان، توضیحات، نام کاربری..."
               value="<?= e($filters['search'] ?? '') ?>">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1">از قیمت</label>
        <input type="number" name="min_price" class="form-control form-control-sm"
               placeholder="USDT" value="<?= e($filters['min_price'] ?? '') ?>" min="0" step="0.01">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1">تا قیمت</label>
        <input type="number" name="max_price" class="form-control form-control-sm"
               placeholder="USDT" value="<?= e($filters['max_price'] ?? '') ?>" min="0" step="0.01">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1">مرتب‌سازی</label>
        <select name="sort" class="form-select form-select-sm">
          <option value="newest"     <?= ($filters['sort'] ?? '') === 'newest'    ? 'selected' : '' ?>>جدیدترین</option>
          <option value="price_asc"  <?= ($filters['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین</option>
          <option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc'? 'selected' : '' ?>>گران‌ترین</option>
          <option value="members"    <?= ($filters['sort'] ?? '') === 'members'   ? 'selected' : '' ?>>بیشترین عضو</option>
        </select>
      </div>
      <div class="col-6 col-md-2 d-flex gap-1">
        <button class="btn btn-primary btn-sm flex-fill">
          <span class="material-icons" style="font-size:15px;vertical-align:middle;">search</span> جستجو
        </button>
        <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
          <span class="material-icons" style="font-size:15px;vertical-align:middle;">refresh</span>
        </a>
      </div>
    </form>
  </div>
</div>

<!-- نتایج -->
<div class="d-flex justify-content-between align-items-center mt-3 mb-2">
  <span class="text-muted small"><?= number_format($total) ?> آگهی یافت شد</span>
</div>

<?php if (empty($listings)): ?>
  <div class="text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">storefront</span>
    <p class="text-muted mt-2">هیچ آگهی فعالی با این فیلترها یافت نشد.</p>
    <a href="<?= url('/vitrine') ?>" class="btn btn-outline-primary btn-sm">نمایش همه</a>
  </div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($listings as $l): ?>
  <?php
    $catIcons = [
      'page' => 'person', 'channel' => 'campaign', 'group' => 'group',
      'vps' => 'dns', 'vpn' => 'vpn_lock', 'website' => 'language', 'other' => 'sell'
    ];
    $catIcon  = $catIcons[$l->category] ?? 'sell';
    $catLabel = $categories[$l->category] ?? $l->category;
    $platLabel= $platforms[$l->platform] ?? $l->platform;
  ?>
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100 vitrine-card" style="transition:box-shadow .2s;">
      <div class="card-body">
        <!-- هدر کارت -->
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="d-flex gap-1 flex-wrap">
            <span class="badge bg-primary bg-opacity-10 text-primary">
              <span class="material-icons" style="font-size:12px;vertical-align:middle;"><?= $catIcon ?></span>
              <?= e($catLabel) ?>
            </span>
            <?php if ($platLabel): ?>
            <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= e($platLabel) ?></span>
            <?php endif; ?>
          </div>
          <!-- KYC نشان -->
          <?php if (($l->seller_kyc ?? '') === 'verified'): ?>
          <span class="badge bg-success" title="فروشنده احراز هویت شده">
            <span class="material-icons" style="font-size:12px;vertical-align:middle;">verified</span>
          </span>
          <?php endif; ?>
        </div>

        <!-- عنوان -->
        <h6 class="fw-bold mb-1 text-truncate" title="<?= e($l->title) ?>">
          <?= e(mb_substr($l->title, 0, 60)) ?>
        </h6>

        <!-- اطلاعات -->
        <?php if (!empty($l->username)): ?>
        <div class="small text-muted mb-1">
          <span class="material-icons" style="font-size:13px;vertical-align:middle;">alternate_email</span>
          <?= e($l->username) ?>
        </div>
        <?php endif; ?>

        <?php if ($l->member_count > 0): ?>
        <div class="small text-muted mb-2">
          <span class="material-icons" style="font-size:13px;vertical-align:middle;">group</span>
          <?= number_format($l->member_count) ?> عضو/فالوور
        </div>
        <?php endif; ?>

        <!-- توضیحات کوتاه -->
        <p class="small text-secondary mb-3" style="
          display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
          <?= e(mb_substr($l->description, 0, 120)) ?>
        </p>

        <!-- قیمت و دکمه -->
        <div class="d-flex justify-content-between align-items-center mt-auto">
          <div>
            <span class="fw-bold text-success fs-6"><?= number_format((float)$l->price_usdt, 2) ?></span>
            <span class="small text-muted"> USDT</span>
          </div>
          <a href="<?= url('/vitrine/' . $l->id) ?>" class="btn btn-sm btn-outline-primary">
            مشاهده
          </a>
        </div>

        <!-- فروشنده -->
        <div class="mt-2 pt-2 border-top d-flex align-items-center justify-content-between">
          <span class="small text-muted">
            <span class="material-icons" style="font-size:12px;vertical-align:middle;">person</span>
            <?= e($l->seller_name ?? '—') ?>
          </span>
          <?php if (!empty($l->seller_tier)): ?>
          <span class="badge bg-warning bg-opacity-20 text-warning" style="font-size:10px;">
            <?= e($l->seller_tier) ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<nav class="d-flex justify-content-center mt-4">
  <ul class="pagination pagination-sm mb-0">
    <?php if ($page > 1): ?>
    <li class="page-item">
      <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>">
        <span class="material-icons" style="font-size:16px;">chevron_right</span>
      </a>
    </li>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
      <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>">
        <?= $i ?>
      </a>
    </li>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
    <li class="page-item">
      <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>">
        <span class="material-icons" style="font-size:16px;">chevron_left</span>
      </a>
    </li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<style>
.vitrine-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.12); }
</style>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
