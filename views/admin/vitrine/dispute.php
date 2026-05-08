<?php $title = 'رسیدگی اختلاف — ویترین'; $layout = 'admin'; ob_start();
$amount = $listing->offer_price_usdt ?? $listing->price_usdt;
$commission = (float) setting('vitrine_commission_percent', '5') / 100;
$sellerNet  = round($amount * (1 - $commission), 6);
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="page-title mb-1">
        <span class="material-icons text-warning align-middle">gavel</span>
        رسیدگی به اختلاف ویترین #<?= e($listing->id) ?>
      </h4>
    </div>
    <a href="<?= url('/admin/vitrine?status=disputed') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</span> بازگشت
    </a>
  </div>

  <div class="row g-3">

    <!-- اطلاعات آگهی -->
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-header">
          <h6 class="card-title mb-0">اطلاعات آگهی</h6>
        </div>
        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <div class="text-muted small">دسته‌بندی</div>
              <div class="fw-medium"><?= e($categories[$listing->category] ?? $listing->category) ?></div>
            </div>
            <div class="col-sm-6">
              <div class="text-muted small">پلتفرم</div>
              <div class="fw-medium"><?= e($listing->platform ?: '—') ?></div>
            </div>
            <div class="col-12">
              <div class="text-muted small">عنوان</div>
              <div class="fw-bold"><?= e($listing->title) ?></div>
            </div>
          </div>
          <div class="mb-3">
            <div class="text-muted small mb-1">توضیحات آگهی</div>
            <div class="bg-light rounded p-3 small" style="white-space:pre-wrap;">
              <?= nl2br(e($listing->description)) ?>
            </div>
          </div>
          <?php if (!empty($listing->admin_note)): ?>
          <div class="alert alert-warning small">
            <strong>یادداشت ادمین / اختلاف:</strong><br>
            <?= nl2br(e($listing->admin_note)) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تاریخچه -->
      <div class="card">
        <div class="card-header">
          <h6 class="card-title mb-0">تاریخچه زمانی</h6>
        </div>
        <div class="card-body">
          <ul class="list-unstyled mb-0" style="border-right:2px solid #dee2e6; padding-right:16px;">
            <li class="mb-3 position-relative">
              <div class="position-absolute" style="width:10px;height:10px;background:#6c757d;border-radius:50%;right:-21px;top:4px;"></div>
              <div class="small text-muted"><?= e(substr($listing->created_at ?? '', 0, 16)) ?></div>
              <div>ثبت آگهی توسط فروشنده</div>
            </li>
            <?php if ($listing->escrow_locked_at): ?>
            <li class="mb-3 position-relative">
              <div class="position-absolute" style="width:10px;height:10px;background:#0d6efd;border-radius:50%;right:-21px;top:4px;"></div>
              <div class="small text-muted"><?= e(substr($listing->escrow_locked_at, 0, 16)) ?></div>
              <div>پرداخت خریدار — قفل escrow</div>
            </li>
            <?php endif; ?>
            <li class="mb-0 position-relative">
              <div class="position-absolute" style="width:10px;height:10px;background:#dc3545;border-radius:50%;right:-21px;top:4px;"></div>
              <div class="small text-muted"><?= e(substr($listing->updated_at ?? '', 0, 16)) ?></div>
              <div class="text-danger fw-medium">ثبت اختلاف</div>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- ستون رأی‌گیری -->
    <div class="col-lg-4">

      <!-- فروشنده -->
      <div class="card mb-3 border-success border-opacity-50">
        <div class="card-header bg-success bg-opacity-10">
          <h6 class="card-title mb-0 text-success">
            <span class="material-icons align-middle" style="font-size:18px;">sell</span>
            فروشنده
          </h6>
        </div>
        <div class="card-body">
          <div class="fw-bold"><?= e($listing->seller_name ?? '—') ?></div>
          <div class="small text-muted">ID: <?= e($listing->seller_id) ?></div>
          <?php if (($listing->seller_kyc ?? '') === 'verified'): ?>
          <span class="badge bg-success mt-1">
            <span class="material-icons" style="font-size:11px;vertical-align:middle;">verified</span> KYC
          </span>
          <?php endif; ?>
          <hr class="my-2">
          <div class="small text-muted">در صورت رأی به نفع فروشنده:</div>
          <div class="fw-bold text-success"><?= number_format($sellerNet, 2) ?> USDT</div>
          <div class="small text-muted">(پس از <?= e(setting('vitrine_commission_percent', '5')) ?>٪ کمیسیون)</div>
        </div>
      </div>

      <!-- خریدار -->
      <div class="card mb-3 border-info border-opacity-50">
        <div class="card-header bg-info bg-opacity-10">
          <h6 class="card-title mb-0 text-info">
            <span class="material-icons align-middle" style="font-size:18px;">shopping_cart</span>
            خریدار
          </h6>
        </div>
        <div class="card-body">
          <div class="fw-bold"><?= e($listing->buyer_name ?? '—') ?></div>
          <div class="small text-muted">ID: <?= e($listing->buyer_id ?? '—') ?></div>
          <?php if (($listing->buyer_kyc ?? '') === 'verified'): ?>
          <span class="badge bg-success mt-1">
            <span class="material-icons" style="font-size:11px;vertical-align:middle;">verified</span> KYC
          </span>
          <?php endif; ?>
          <hr class="my-2">
          <div class="small text-muted">در صورت رأی به نفع خریدار:</div>
          <div class="fw-bold text-info"><?= number_format((float)$amount, 2) ?> USDT</div>
          <div class="small text-muted">(استرداد کامل)</div>
        </div>
      </div>

      <!-- مبلغ در escrow -->
      <div class="card mb-3">
        <div class="card-body text-center">
          <div class="text-muted small">مبلغ قفل شده در Escrow</div>
          <div class="h3 fw-bold text-primary"><?= number_format((float)$amount, 2) ?> USDT</div>
        </div>
      </div>

      <!-- دکمه‌های رأی -->
      <div class="card border-warning">
        <div class="card-header bg-warning bg-opacity-15">
          <h6 class="card-title mb-0">تصمیم نهایی</h6>
        </div>
        <div class="card-body">
          <p class="small text-muted mb-3">
            این تصمیم غیرقابل بازگشت است. لطفاً توضیحات و شواهد هر دو طرف را بررسی کنید.
          </p>
          <div class="d-grid gap-2">
            <button class="btn btn-success" onclick="resolveDispute('seller')">
              <span class="material-icons align-middle" style="font-size:18px;">sell</span>
              فروشنده برنده است — پرداخت وجه
            </button>
            <button class="btn btn-info text-white" onclick="resolveDispute('buyer')">
              <span class="material-icons align-middle" style="font-size:18px;">shopping_cart</span>
              خریدار برنده است — استرداد وجه
            </button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const ID   = <?= (int)$listing->id ?>;

function resolveDispute(winner) {
  const msg = winner === 'seller'
    ? `وجه ${<?= number_format($sellerNet, 2) ?>} USDT به فروشنده پرداخت شود؟`
    : `وجه ${<?= number_format((float)$amount, 2) ?>} USDT به خریدار استرداد شود؟`;

  if (!confirm(msg + '\n\nاین عمل غیرقابل بازگشت است.')) return;

  fetch(`/admin/vitrine/${ID}/resolve`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ winner })
  })
  .then(r => r.json())
  .then(d => {
    alert(d.success ? '✅ ' + (d.message || 'انجام شد') : '❌ ' + (d.message || 'خطا'));
    if (d.success) window.location.href = '/admin/vitrine?status=disputed';
  });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
