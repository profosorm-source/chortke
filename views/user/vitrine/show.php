<?php $layout = 'user'; ob_start();
$csrf = csrf_token();
$id   = (int)$listing->id;
$catLabel  = $categories[$listing->category]  ?? $listing->category;
$platLabel = $platforms[$listing->platform]   ?? $listing->platform;
$statLabel = $statuses[$listing->status]      ?? $listing->status;

$isSell    = $listing->listing_type === 'sell';
$amount    = $listing->offer_price_usdt ?? $listing->price_usdt;

$statusColors = [
  'pending'   => 'warning', 'active'   => 'success',
  'in_escrow' => 'primary', 'disputed' => 'danger',
  'sold'      => 'secondary','cancelled'=> 'secondary', 'rejected' => 'danger',
];
$statusColor = $statusColors[$listing->status] ?? 'secondary';

$catIcons = [
  'page'=>'person','channel'=>'campaign','group'=>'group',
  'vps'=>'dns','vpn'=>'vpn_lock','website'=>'language','other'=>'sell'
];
$catIcon = $catIcons[$listing->category] ?? 'sell';
?>

<div class="content-header d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-primary align-middle"><?= $catIcon ?></span>
      <?= e($listing->title) ?>
    </h4>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge bg-primary bg-opacity-10 text-primary"><?= e($catLabel) ?></span>
      <?php if ($platLabel): ?>
      <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= e($platLabel) ?></span>
      <?php endif; ?>
      <span class="badge bg-<?= $statusColor ?>"><?= e($statLabel) ?></span>
      <?php if (!$isSell): ?>
      <span class="badge bg-info bg-opacity-20 text-info">درخواست خرید</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <?php if (!$isSeller && $listing->status === 'active'): ?>
    <button class="btn btn-outline-<?= $isWatched ? 'warning' : 'secondary' ?> btn-sm" id="watchBtn"
            onclick="toggleWatch()" title="<?= $isWatched ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها' ?>">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">
        <?= $isWatched ? 'bookmark' : 'bookmark_border' ?>
      </span>
      <span id="watchCount"><?= $watchCount ?></span>
    </button>
    <?php endif; ?>
    <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</span> بازگشت
    </a>
  </div>
</div>

<div class="row mt-3 g-3">

  <!-- ستون اصلی: توضیحات -->
  <div class="col-lg-8">

    <!-- توضیحات اصلی -->
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <span class="material-icons" style="font-size:18px;">article</span>
        <h6 class="card-title mb-0">توضیحات آگهی</h6>
      </div>
      <div class="card-body">
        <div class="vitrine-description" style="white-space:pre-wrap;line-height:1.8;font-size:14px;">
          <?= nl2br(e($listing->description)) ?>
        </div>
      </div>
    </div>

    <!-- مشخصات فنی -->
    <?php if (!empty($listing->specs)): ?>
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <span class="material-icons" style="font-size:18px;">settings</span>
        <h6 class="card-title mb-0">مشخصات فنی</h6>
      </div>
      <div class="card-body">
        <div style="white-space:pre-wrap;line-height:1.8;font-size:13px;" class="text-secondary">
          <?= nl2br(e($listing->specs)) ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- آمار -->
    <?php if ($listing->member_count > 0 || !empty($listing->username) || !empty($listing->creation_date)): ?>
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <span class="material-icons" style="font-size:18px;">bar_chart</span>
        <h6 class="card-title mb-0">آمار و اطلاعات</h6>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <?php if (!empty($listing->username)): ?>
          <div class="col-sm-4">
            <div class="text-muted small">نام کاربری</div>
            <div class="fw-medium"><?= e($listing->username) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($listing->member_count > 0): ?>
          <div class="col-sm-4">
            <div class="text-muted small">تعداد اعضا</div>
            <div class="fw-medium"><?= number_format((int)$listing->member_count) ?></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($listing->creation_date)): ?>
          <div class="col-sm-4">
            <div class="text-muted small">تاریخ تأسیس</div>
            <div class="fw-medium"><?= e(substr($listing->creation_date, 0, 10)) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- درخواست‌های خریداران (فقط برای فروشنده) -->
    <?php if ($isSeller && !empty($requests)): ?>
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <span class="material-icons" style="font-size:18px;">inbox</span>
          <h6 class="card-title mb-0">درخواست‌های خریداران</h6>
        </div>
        <span class="badge bg-primary"><?= count($requests) ?></span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 small">
            <thead class="table-light">
              <tr>
                <th>خریدار</th>
                <th>قیمت پیشنهادی</th>
                <th>پیام</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <?php if ($listing->status === 'active'): ?>
                <th>عملیات</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $req): ?>
              <?php
                $reqColors = ['pending'=>'warning','accepted'=>'success','rejected'=>'secondary'];
                $reqLabels = ['pending'=>'در انتظار','accepted'=>'پذیرفته','rejected'=>'رد شده'];
              ?>
              <tr>
                <td>
                  <div class="fw-medium"><?= e($req->requester_name ?? '—') ?></div>
                  <?php if (($req->kyc_status ?? '') === 'verified'): ?>
                  <span class="badge bg-success" style="font-size:10px;">
                    <span class="material-icons" style="font-size:10px;vertical-align:middle;">verified</span> KYC
                  </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($req->offer_price): ?>
                  <span class="fw-bold text-success"><?= number_format((float)$req->offer_price, 2) ?> USDT</span>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td style="max-width:200px;">
                  <span title="<?= e($req->message ?? '') ?>">
                    <?= e(mb_substr($req->message ?? '—', 0, 60)) ?>
                    <?= mb_strlen($req->message ?? '') > 60 ? '...' : '' ?>
                  </span>
                </td>
                <td>
                  <span class="badge bg-<?= $reqColors[$req->status] ?? 'secondary' ?>">
                    <?= $reqLabels[$req->status] ?? $req->status ?>
                  </span>
                </td>
                <td><?= e(substr($req->created_at ?? '', 0, 10)) ?></td>
                <?php if ($listing->status === 'active'): ?>
                <td>
                  <?php if ($req->status === 'pending'): ?>
                  <div class="d-flex gap-1">
                    <button class="btn btn-success btn-sm"
                            onclick="acceptRequest(<?= $req->id ?>)">
                      <span class="material-icons" style="font-size:14px;">check</span>
                    </button>
                    <button class="btn btn-outline-danger btn-sm"
                            onclick="rejectRequest(<?= $req->id ?>)">
                      <span class="material-icons" style="font-size:14px;">close</span>
                    </button>
                  </div>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- ستون جانبی: اقدامات -->
  <div class="col-lg-4">

    <!-- کارت قیمت و عملیات -->
    <div class="card mb-3">
      <div class="card-body text-center">

        <!-- قیمت -->
        <div class="mb-3">
          <div class="h2 fw-bold text-success mb-0">
            <?= number_format((float)$listing->price_usdt, 2) ?>
          </div>
          <div class="text-muted">USDT</div>
          <?php if ($listing->offer_price_usdt && $listing->offer_price_usdt != $listing->price_usdt): ?>
          <div class="badge bg-warning bg-opacity-20 text-warning mt-1">
            قیمت توافقی: <?= number_format((float)$listing->offer_price_usdt, 2) ?> USDT
          </div>
          <?php endif; ?>
        </div>

        <!-- اطلاعات فروشنده -->
        <div class="d-flex align-items-center justify-content-center gap-2 mb-3 text-muted small">
          <span class="material-icons" style="font-size:16px;">person</span>
          <span><?= $isSell ? 'فروشنده' : 'متقاضی' ?>: <?= e($listing->seller_name ?? '—') ?></span>
          <?php if (($listing->seller_kyc ?? '') === 'verified'): ?>
          <span class="badge bg-success" title="احراز هویت شده">
            <span class="material-icons" style="font-size:10px;vertical-align:middle;">verified</span>
          </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($listing->seller_tier)): ?>
        <div class="mb-3">
          <span class="badge bg-warning bg-opacity-20 text-warning"><?= e($listing->seller_tier) ?></span>
        </div>
        <?php endif; ?>

        <!-- زمان ثبت -->
        <div class="text-muted small mb-3">
          ثبت شده: <?= e(substr($listing->created_at ?? '', 0, 10)) ?>
        </div>

        <hr class="my-3">

        <!-- دکمه‌های عملیات -->

        <?php if ($isSeller): ?>
          <!-- فروشنده/متقاضی -->
          <div class="alert alert-light text-start small mb-2">
            <strong>آگهی شما</strong> — وضعیت: <?= e($statLabel) ?>
            <?php if ($listing->status === 'in_escrow'): ?>
            <br><span class="text-primary">
              <span class="material-icons" style="font-size:13px;vertical-align:middle;">lock</span>
              اسکرو فعال — منتظر تایید خریدار
            </span>
            <?php if ($listing->seller_info_sent == 0): ?>
            <div class="mt-2 text-warning">
              <span class="material-icons" style="font-size:13px;vertical-align:middle;">warning</span>
              لطفاً اطلاعات دسترسی را از طریق تیکت به خریدار ارسال کنید
            </div>
            <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if (!empty($listing->escrow_deadline)): ?>
          <div class="alert alert-warning small mb-2">
            <span class="material-icons" style="font-size:13px;vertical-align:middle;">schedule</span>
            ددلاین تایید: <?= e(substr($listing->escrow_deadline, 0, 16)) ?>
          </div>
          <?php endif; ?>

        <?php elseif ($isBuyer): ?>
          <!-- خریدار -->
          <?php if ($listing->status === 'in_escrow'): ?>
          <div class="alert alert-info small text-start mb-2">
            <span class="material-icons" style="font-size:13px;vertical-align:middle;">info</span>
            پرداخت شما در escrow قفل است. پس از دریافت اطلاعات دسترسی، تایید کنید.
          </div>
          <?php if ($listing->escrow_deadline): ?>
          <div class="alert alert-warning small mb-2">
            <span class="material-icons" style="font-size:13px;vertical-align:middle;">schedule</span>
            مهلت تایید شما: <?= e(substr($listing->escrow_deadline, 0, 16)) ?>
            <br><small>اگر تایید نکنید، وجه خودکار به فروشنده پرداخت می‌شود</small>
          </div>
          <?php endif; ?>
          <button class="btn btn-success w-100 mb-2" onclick="confirmDelivery()">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">check_circle</span>
            تایید دریافت و آزاد کردن وجه
          </button>
          <button class="btn btn-outline-danger w-100 btn-sm" onclick="openDispute()">
            <span class="material-icons" style="font-size:14px;vertical-align:middle;">gavel</span>
            ثبت شکایت / اختلاف
          </button>
          <?php endif; ?>

        <?php elseif ($listing->status === 'active' && $isSell): ?>
          <!-- خریدار احتمالی — آگهی فروش -->
          <button class="btn btn-primary w-100 mb-2" onclick="openRequestModal()">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">shopping_cart</span>
            درخواست خرید / قیمت پیشنهادی
          </button>
          <a href="<?= url('/messages/' . (int)$listing->seller_id) ?>" class="btn btn-outline-indigo w-100 mb-3">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">forum</span>
            ارسال پیام مستقیم به فروشنده
          </a>
          <div class="text-muted small">
            <span class="material-icons" style="font-size:13px;vertical-align:middle;">lock</span>
            پس از توافق، پرداخت در escrow قفل می‌شود
          </div>

        <?php elseif ($listing->status === 'active' && !$isSell): ?>
          <!-- فروشنده احتمالی — آگهی خریدار -->
          <button class="btn btn-success w-100 mb-2" onclick="openContactModal()">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">sell</span>
            من این رو دارم — ارسال پیشنهاد
          </button>
          <a href="<?= url('/messages/' . (int)$listing->seller_id) ?>" class="btn btn-outline-indigo w-100 mb-2">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">forum</span>
            ارسال پیام مستقیم به خریدار
          </a>

        <?php elseif ($listing->status === 'sold'): ?>
          <div class="alert alert-secondary small">
            <span class="material-icons" style="font-size:13px;vertical-align:middle;">done_all</span>
            معامله با موفقیت تکمیل شده است
          </div>

        <?php elseif ($listing->status === 'in_escrow'): ?>
          <div class="alert alert-primary small">
            <span class="material-icons" style="font-size:13px;vertical-align:middle;">lock</span>
            این آگهی در حال انتقال است
          </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- راهنمای فرایند -->
    <?php if (!$isSeller && !$isBuyer && $listing->status === 'active'): ?>
    <div class="card border-0 bg-light mb-3">
      <div class="card-body py-3 px-3">
        <p class="small fw-medium mb-2">فرایند خرید در ویترین:</p>
        <ol class="small text-muted mb-0 ps-3">
          <li>درخواست خود را ارسال کنید</li>
          <li>فروشنده درخواست را بررسی می‌کند</li>
          <li>پس از توافق، پرداخت در escrow قفل می‌شود</li>
          <li>فروشنده اطلاعات دسترسی را ارسال می‌کند</li>
          <li>شما <?= e(setting('vitrine_escrow_days', '3')) ?> روز فرصت تست دارید</li>
          <li>تایید شما ← پرداخت به فروشنده</li>
        </ol>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- Modal: درخواست خرید -->
<div class="modal fade" id="requestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">
          <span class="material-icons align-middle" style="font-size:18px;">shopping_cart</span>
          درخواست خرید
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">قیمت پیشنهادی (اختیاری)</label>
          <div class="input-group">
            <input type="number" id="offerPrice" class="form-control" min="1" step="0.01"
                   placeholder="<?= number_format((float)$listing->price_usdt, 2) ?>">
            <span class="input-group-text">USDT</span>
          </div>
          <?php if ($listing->min_price_usdt): ?>
          <div class="form-text">حداقل قیمت قابل قبول: <?= number_format((float)$listing->min_price_usdt, 2) ?> USDT</div>
          <?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="form-label">پیام به فروشنده <span class="text-danger">*</span></label>
          <textarea id="requestMessage" class="form-control" rows="4"
                    placeholder="خودتان را معرفی کنید و سوالاتتان را مطرح کنید..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" onclick="submitRequest()">ارسال درخواست</button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: پیام به متقاضی (برای آگهی خریدار) -->
<div class="modal fade" id="contactModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">ارسال پیشنهاد فروش</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">قیمت پیشنهادی شما <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="number" id="contactOfferPrice" class="form-control" min="1" step="0.01">
            <span class="input-group-text">USDT</span>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">توضیح محصول شما <span class="text-danger">*</span></label>
          <textarea id="contactMessage" class="form-control" rows="4"
                    placeholder="مشخصات محصولی که دارید و با درخواست خریدار مطابقت دارد را بنویسید..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success" onclick="submitContact()">ارسال پیشنهاد</button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: اختلاف -->
<div class="modal fade" id="disputeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-danger">
        <h6 class="modal-title text-danger">
          <span class="material-icons align-middle" style="font-size:18px;">gavel</span>
          ثبت شکایت / اختلاف
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning small">
          این درخواست به تیم پشتیبانی ارسال می‌شود و ظرف ۲۴-۴۸ ساعت بررسی می‌گردد.
        </div>
        <div class="mb-3">
          <label class="form-label">دلیل اختلاف <span class="text-danger">*</span></label>
          <textarea id="disputeReason" class="form-control" rows="5"
                    placeholder="دلیل شکایت را با جزئیات کامل بنویسید..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger" onclick="submitDispute()">ثبت شکایت</button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF   = '<?= $csrf ?>';
const ID     = <?= $id ?>;
const headers = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF };

function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `alert alert-${type} position-fixed shadow`;
  el.style.cssText = 'top:20px;left:50%;transform:translateX(-50%);z-index:9999;min-width:280px;text-align:center;';
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

// ─── علاقه‌مندی ─────────────────────────────────────────────────────────────
function toggleWatch() {
  fetch(`<?= url('/vitrine/') ?>${ID}/watch`, { method: 'POST', headers })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        toast(d.message, 'info');
        const btn  = document.getElementById('watchBtn');
        const icon = btn.querySelector('.material-icons');
        const cnt  = document.getElementById('watchCount');
        const isNow = d.watched;
        icon.textContent = isNow ? 'bookmark' : 'bookmark_border';
        btn.className = `btn btn-outline-${isNow ? 'warning' : 'secondary'} btn-sm`;
        cnt.textContent = parseInt(cnt.textContent || 0) + (isNow ? 1 : -1);
      } else toast(d.message, 'danger');
    });
}

// ─── درخواست خرید ────────────────────────────────────────────────────────────
function openRequestModal() {
  new bootstrap.Modal(document.getElementById('requestModal')).show();
}

function submitRequest() {
  const msg   = document.getElementById('requestMessage').value.trim();
  const price = document.getElementById('offerPrice').value;
  if (!msg) { toast('پیام به فروشنده الزامی است.', 'warning'); return; }

  fetch(`<?= url('/vitrine/') ?>${ID}/request`, {
    method: 'POST', headers,
    body: JSON.stringify({ message: msg, offer_price: price || null })
  }).then(r => r.json()).then(d => {
    bootstrap.Modal.getInstance(document.getElementById('requestModal'))?.hide();
    toast(d.message, d.success ? 'success' : 'danger');
  });
}

// ─── پیشنهاد فروش (آگهی خریدار) ────────────────────────────────────────────
function openContactModal() {
  new bootstrap.Modal(document.getElementById('contactModal')).show();
}

function submitContact() {
  const msg   = document.getElementById('contactMessage').value.trim();
  const price = document.getElementById('contactOfferPrice').value;
  if (!msg || !price) { toast('قیمت و توضیحات الزامی هستند.', 'warning'); return; }

  fetch(`<?= url('/vitrine/') ?>${ID}/request`, {
    method: 'POST', headers,
    body: JSON.stringify({ message: msg, offer_price: price })
  }).then(r => r.json()).then(d => {
    bootstrap.Modal.getInstance(document.getElementById('contactModal'))?.hide();
    toast(d.message, d.success ? 'success' : 'danger');
  });
}

// ─── پذیرش / رد درخواست ────────────────────────────────────────────────────
function acceptRequest(reqId) {
  if (!confirm('درخواست این خریدار پذیرفته شود؟')) return;
  fetch(`<?= url('/vitrine/request/') ?>${reqId}/accept`, { method: 'POST', headers, body: '{}' })
    .then(r => r.json())
    .then(d => { toast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 1500); });
}

function rejectRequest(reqId) {
  if (!confirm('این درخواست رد شود؟')) return;
  fetch(`<?= url('/vitrine/request/') ?>${reqId}/reject`, { method: 'POST', headers, body: '{}' })
    .then(r => r.json())
    .then(d => { toast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 1500); });
}

// ─── خرید / قفل escrow ──────────────────────────────────────────────────────
function buyListing() {
  if (!confirm('آیا از پرداخت اطمینان دارید؟ مبلغ تا تایید شما در escrow نگه داشته می‌شود.')) return;
  fetch(`<?= url('/vitrine/') ?>${ID}/buy`, { method: 'POST', headers, body: '{}' })
    .then(r => r.json())
    .then(d => { toast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 1800); });
}

// ─── تایید دریافت ────────────────────────────────────────────────────────────
function confirmDelivery() {
  if (!confirm('آیا اطلاعات دسترسی را دریافت کرده و از صحت آن مطمئن هستید؟\nبا تایید شما، وجه به فروشنده پرداخت می‌شود.')) return;
  fetch(`<?= url('/vitrine/') ?>${ID}/confirm`, { method: 'POST', headers, body: '{}' })
    .then(r => r.json())
    .then(d => { toast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 1800); });
}

// ─── اختلاف ──────────────────────────────────────────────────────────────────
function openDispute() {
  new bootstrap.Modal(document.getElementById('disputeModal')).show();
}

function submitDispute() {
  const reason = document.getElementById('disputeReason').value.trim();
  if (reason.length < 10) { toast('لطفاً دلیل اختلاف را با جزئیات بیشتر بنویسید.', 'warning'); return; }
  fetch(`<?= url('/vitrine/') ?>${ID}/dispute`, {
    method: 'POST', headers,
    body: JSON.stringify({ reason })
  }).then(r => r.json()).then(d => {
    bootstrap.Modal.getInstance(document.getElementById('disputeModal'))?.hide();
    toast(d.message, d.success ? 'success' : 'danger');
    if (d.success) setTimeout(() => location.reload(), 1800);
  });
}
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
