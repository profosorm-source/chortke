<?php $title = 'مدیریت ویترین'; $layout = 'admin'; ob_start(); ?>

<div class="container-fluid">

  <!-- هدر -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="page-title mb-1">
        <span class="material-icons text-primary align-middle">storefront</span>
        مدیریت ویترین
      </h4>
      <p class="text-muted mb-0" style="font-size:12px;">
        نظارت بر آگهی‌های متنی خرید و فروش — escrow — اختلافات
      </p>
    </div>
    <a href="<?= url('/admin/vitrine/settings') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">settings</span> تنظیمات ویترین
    </a>
  </div>

  <!-- کارت‌های آمار -->
  <?php if (isset($stats)): ?>
  <div class="row g-3 mb-4">
    <?php
    $statCards = [
      ['label'=>'کل آگهی‌ها',        'val'=>$stats->total,     'icon'=>'list_alt',  'color'=>'primary'],
      ['label'=>'در انتظار تایید',   'val'=>$stats->pending,   'icon'=>'pending',   'color'=>'warning'],
      ['label'=>'فعال',              'val'=>$stats->active,    'icon'=>'check',     'color'=>'success'],
      ['label'=>'در escrow',         'val'=>$stats->in_escrow, 'icon'=>'lock',      'color'=>'info'],
      ['label'=>'اختلاف',           'val'=>$stats->disputed,  'icon'=>'gavel',     'color'=>'danger'],
      ['label'=>'فروخته شده',       'val'=>$stats->sold,      'icon'=>'done_all',  'color'=>'secondary'],
    ];
    ?>
    <?php foreach ($statCards as $sc): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card text-center border-0 shadow-sm">
        <div class="card-body py-3">
          <span class="material-icons text-<?= $sc['color'] ?>" style="font-size:28px;"><?= $sc['icon'] ?></span>
          <div class="h4 fw-bold mb-0 mt-1"><?= number_format((int)$sc['val']) ?></div>
          <div class="small text-muted"><?= $sc['label'] ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- فیلترها -->
  <div class="card mb-3">
    <div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
          <select name="status" class="form-select form-select-sm">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach ($statuses as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>>
              <?= e($v) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
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
          <select name="type" class="form-select form-select-sm">
            <option value="">فروش + خرید</option>
            <option value="sell" <?= ($filters['type'] ?? '') === 'sell' ? 'selected' : '' ?>>فقط فروش</option>
            <option value="buy"  <?= ($filters['type'] ?? '') === 'buy'  ? 'selected' : '' ?>>فقط خرید</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="جستجو نام، عنوان..." value="<?= e($filters['search'] ?? '') ?>">
        </div>
        <div class="col-12 col-md-3 d-flex gap-1">
          <button class="btn btn-primary btn-sm flex-fill">فیلتر</button>
          <a href="<?= url('/admin/vitrine') ?>" class="btn btn-outline-secondary btn-sm">پاک‌سازی</a>
        </div>
      </form>
    </div>
  </div>

  <!-- جدول -->
  <div class="card">
    <div class="card-body p-0">
      <?php if (empty($listings)): ?>
      <div class="text-center py-5 text-muted">
        <span class="material-icons" style="font-size:48px;">storefront</span>
        <br>هیچ آگهی‌ای یافت نشد.
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0 small">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>نوع / دسته</th>
              <th>عنوان</th>
              <th>فروشنده</th>
              <th>قیمت USDT</th>
              <th>وضعیت</th>
              <th>تاریخ</th>
              <th style="min-width:140px;">عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($listings as $l): ?>
            <?php
              $sc = ['pending'=>'warning','active'=>'success','in_escrow'=>'primary',
                     'disputed'=>'danger','sold'=>'secondary','cancelled'=>'secondary','rejected'=>'danger'];
              $statusColor = $sc[$l->status] ?? 'secondary';
              $statusLabel = $statuses[$l->status] ?? $l->status;
              $catLabel    = $categories[$l->category] ?? $l->category;
              $isSell      = $l->listing_type === 'sell';
            ?>
            <tr>
              <td class="text-muted"><?= e($l->id) ?></td>
              <td>
                <div>
                  <span class="badge bg-<?= $isSell ? 'success' : 'info' ?> bg-opacity-15 text-<?= $isSell ? 'success' : 'info' ?>" style="font-size:10px;">
                    <?= $isSell ? 'فروش' : 'خرید' ?>
                  </span>
                </div>
                <span class="text-muted" style="font-size:11px;"><?= e($catLabel) ?></span>
              </td>
              <td style="max-width:220px;">
                <a href="<?= url('/vitrine/' . $l->id) ?>" target="_blank"
                   class="fw-medium text-decoration-none text-dark">
                  <?= e(mb_substr($l->title, 0, 45)) ?><?= mb_strlen($l->title) > 45 ? '...' : '' ?>
                </a>
                <?php if ($l->buyer_name): ?>
                <div class="text-muted" style="font-size:11px;">خریدار: <?= e($l->buyer_name) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?= e($l->seller_name ?? '—') ?>
                <?php if (($l->seller_kyc ?? '') === 'verified'): ?>
                <span class="material-icons text-success" style="font-size:13px;vertical-align:middle;" title="KYC">verified</span>
                <?php endif; ?>
                <?php if (!empty($l->seller_tier)): ?>
                <div><span class="badge bg-warning bg-opacity-15 text-warning" style="font-size:10px;"><?= e($l->seller_tier) ?></span></div>
                <?php endif; ?>
              </td>
              <td class="fw-bold">
                <?= number_format((float)$l->price_usdt, 2) ?>
                <?php if ($l->offer_price_usdt && $l->offer_price_usdt != $l->price_usdt): ?>
                <div class="text-warning" style="font-size:11px;">(<?= number_format((float)$l->offer_price_usdt, 2) ?>)</div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-<?= $statusColor ?>"><?= e($statusLabel) ?></span>
                <?php if ($l->status === 'in_escrow' && $l->escrow_deadline): ?>
                <div class="text-muted mt-1" style="font-size:10px;">
                  <span class="material-icons" style="font-size:10px;vertical-align:middle;">schedule</span>
                  <?= e(substr($l->escrow_deadline, 0, 10)) ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= e(substr($l->created_at ?? '', 0, 10)) ?></td>
              <td>
                <div class="d-flex gap-1 flex-wrap">
                  <!-- مشاهده -->
                  <a href="<?= url('/vitrine/' . $l->id) ?>" target="_blank"
                     class="btn btn-outline-secondary btn-sm" title="مشاهده">
                    <span class="material-icons" style="font-size:14px;">visibility</span>
                  </a>

                  <!-- تایید/رد: pending -->
                  <?php if ($l->status === 'pending'): ?>
                  <button class="btn btn-success btn-sm" title="تایید آگهی"
                          onclick="approveItem(<?= $l->id ?>)">
                    <span class="material-icons" style="font-size:14px;">check</span>
                  </button>
                  <button class="btn btn-danger btn-sm" title="رد آگهی"
                          onclick="openReject(<?= $l->id ?>)">
                    <span class="material-icons" style="font-size:14px;">close</span>
                  </button>
                  <?php endif; ?>

                  <!-- رسیدگی اختلاف -->
                  <?php if ($l->status === 'disputed'): ?>
                  <a href="<?= url('/admin/vitrine/' . $l->id . '/dispute') ?>"
                     class="btn btn-warning btn-sm" title="رسیدگی به اختلاف">
                    <span class="material-icons" style="font-size:14px;">gavel</span>
                  </a>
                  <?php endif; ?>

                  <!-- آزادسازی دستی escrow -->
                  <?php if ($l->status === 'in_escrow'): ?>
                  <button class="btn btn-primary btn-sm" title="آزادسازی وجه به فروشنده"
                          onclick="releaseFunds(<?= $l->id ?>)">
                    <span class="material-icons" style="font-size:14px;">payments</span>
                  </button>
                  <button class="btn btn-outline-danger btn-sm" title="استرداد به خریدار"
                          onclick="refundBuyer(<?= $l->id ?>)">
                    <span class="material-icons" style="font-size:14px;">undo</span>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if (($pages ?? 1) > 1): ?>
      <div class="d-flex justify-content-center p-3">
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
            <li class="page-item <?= $i === ($page ?? 1) ? 'active' : '' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>">
                <?= $i ?>
              </a>
            </li>
            <?php endfor; ?>
          </ul>
        </nav>
      </div>
      <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Modal: رد آگهی -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">رد آگهی</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <textarea id="rejectReason" class="form-control" rows="3"
                  placeholder="دلیل رد (اختیاری)"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger btn-sm" id="confirmRejectBtn">رد کن</button>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">لغو</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const H    = { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF };

let pendingRejectId = null;

function approveItem(id) {
  if (!confirm('آیا این آگهی را تایید و منتشر می‌کنید؟')) return;
  fetch(`/admin/vitrine/${id}/approve`, { method: 'POST', headers: H })
    .then(r => r.json())
    .then(d => { alert(d.message); if (d.success) location.reload(); });
}

function openReject(id) {
  pendingRejectId = id;
  document.getElementById('rejectReason').value = '';
  new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

document.getElementById('confirmRejectBtn')?.addEventListener('click', function () {
  if (!pendingRejectId) return;
  const reason = document.getElementById('rejectReason').value;
  fetch(`/admin/vitrine/${pendingRejectId}/reject`, {
    method: 'POST', headers: H, body: JSON.stringify({ reason })
  }).then(r => r.json()).then(d => {
    bootstrap.Modal.getInstance(document.getElementById('rejectModal'))?.hide();
    if (d.success) location.reload(); else alert(d.message);
  });
});

function releaseFunds(id) {
  if (!confirm('آزادسازی وجه به فروشنده؟ این عمل غیرقابل بازگشت است.')) return;
  fetch(`/admin/vitrine/${id}/release`, { method: 'POST', headers: H })
    .then(r => r.json())
    .then(d => { alert(d.message); if (d.success) location.reload(); });
}

function refundBuyer(id) {
  if (!confirm('استرداد وجه به خریدار؟ این عمل غیرقابل بازگشت است.')) return;
  fetch(`/admin/vitrine/${id}/refund`, { method: 'POST', headers: H })
    .then(r => r.json())
    .then(d => { alert(d.message); if (d.success) location.reload(); });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
