<?php ob_start();
$resultMap = ['home' => 'خانه برد', 'away' => 'مهمان برد', 'draw' => 'مساوی'];
$predMap   = ['home' => 'خانه',     'away' => 'مهمان',      'draw' => 'مساوی'];
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="page-title mb-1">
        <span class="material-icons text-primary" style="vertical-align:middle;">sports_soccer</span>
        <?= e($game->title) ?>
      </h4>
      <p class="text-muted mb-0" style="font-size:12px;">
        بازی #<?= (int)$game->id ?> ·
        <?= e($game->team_home) ?> vs <?= e($game->team_away) ?>
      </p>
    </div>
    <a href="<?= url('/admin/prediction') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
    </a>
  </div>

  <div class="row">
    <!-- ستون چپ: اطلاعات بازی -->
    <div class="col-md-5">
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">اطلاعات بازی</h6>
          <?php
            $sc = ['open'=>'success','closed'=>'warning','finished'=>'primary','cancelled'=>'secondary'];
            $sl = ['open'=>'باز','closed'=>'بسته','finished'=>'پایان یافته','cancelled'=>'لغو'];
          ?>
          <span class="badge bg-<?= $sc[$game->status] ?? 'secondary' ?>"><?= $sl[$game->status] ?? $game->status ?></span>
        </div>
        <div class="card-body">
          <!-- تیم‌ها -->
          <div class="d-flex justify-content-around align-items-center py-2 mb-3 bg-light rounded">
            <div class="text-center">
              <div class="fw-bold fs-5"><?= e($game->team_home) ?></div>
              <small class="text-muted">خانه</small>
            </div>
            <div class="text-center">
              <span class="badge bg-secondary px-3 py-2 fs-6">VS</span>
            </div>
            <div class="text-center">
              <div class="fw-bold fs-5"><?= e($game->team_away) ?></div>
              <small class="text-muted">مهمان</small>
            </div>
          </div>

          <table class="table table-bordered table-sm mb-3">
            <tr><th>ورزش</th><td><?= e($game->sport_type ?? 'football') ?></td></tr>
            <tr><th>تاریخ بازی</th><td><?= e($game->match_date) ?></td></tr>
            <tr><th>ددلاین شرط</th><td><?= e($game->bet_deadline) ?></td></tr>
            <tr><th>محدوده شرط</th><td><?= number_format((float)$game->min_bet_usdt, 2) ?> – <?= number_format((float)$game->max_bet_usdt, 2) ?> USDT</td></tr>
            <tr><th>کمیسیون</th><td><?= number_format((float)$game->commission_percent, 1) ?>٪</td></tr>
            <tr>
              <th>استخر کل</th>
              <td class="fw-bold text-success"><?= number_format((float)($game->total_pool ?? 0), 4) ?> USDT</td>
            </tr>
            <tr>
              <th>استخر خالص</th>
              <td class="fw-bold">
                <?= number_format((float)($game->total_pool ?? 0) * (1 - (float)$game->commission_percent / 100), 4) ?> USDT
              </td>
            </tr>
            <?php if($game->result): ?>
            <tr>
              <th>نتیجه</th>
              <td><span class="badge bg-info"><?= $resultMap[$game->result] ?? $game->result ?></span>
                <?php if($game->winners_paid): ?> <span class="badge bg-success">✓ تسویه شده</span><?php endif; ?>
              </td>
            </tr>
            <?php endif; ?>
          </table>

          <!-- توزیع شرط‌ها (progress bars) -->
          <?php
            $pool = (float)($game->total_pool ?? 0);
            $ph   = (float)($dist->pool_home ?? 0);
            $pa   = (float)($dist->pool_away ?? 0);
            $pd   = (float)($dist->pool_draw ?? 0);
            $pct  = fn($v) => $pool > 0 ? round($v / $pool * 100) : 0;
          ?>
          <div class="mb-3">
            <small class="text-muted">توزیع شرط‌ها</small>
            <div class="d-flex justify-content-between small mb-1 mt-1">
              <span>🏠 خانه <?= $pct($ph) ?>٪</span>
              <span>🤝 مساوی <?= $pct($pd) ?>٪</span>
              <span>✈️ مهمان <?= $pct($pa) ?>٪</span>
            </div>
            <div class="progress" style="height:10px;">
              <div class="progress-bar bg-primary"  style="width:<?= $pct($ph) ?>%" title="خانه"></div>
              <div class="progress-bar bg-secondary" style="width:<?= $pct($pd) ?>%" title="مساوی"></div>
              <div class="progress-bar bg-success"  style="width:<?= $pct($pa) ?>%" title="مهمان"></div>
            </div>
            <div class="d-flex justify-content-between small text-muted mt-1">
              <span><?= number_format($ph, 2) ?> USDT</span>
              <span><?= number_format($pd, 2) ?> USDT</span>
              <span><?= number_format($pa, 2) ?> USDT</span>
            </div>
          </div>

          <!-- دکمه‌های عملیات -->
          <?php if($game->status === 'open'): ?>
          <div class="d-grid gap-2">
            <button class="btn btn-outline-warning btn-sm" id="btnCloseBetting">
              <span class="material-icons" style="font-size:15px;vertical-align:middle;">lock</span>
              بستن شرط‌گیری
            </button>
            <button class="btn btn-warning btn-sm" id="btnSettle">
              <span class="material-icons" style="font-size:15px;vertical-align:middle;">flag</span>
              ثبت نتیجه + تسویه
            </button>
            <button class="btn btn-danger btn-sm" id="btnCancel">
              <span class="material-icons" style="font-size:15px;vertical-align:middle;">cancel</span>
              لغو بازی
            </button>
          </div>
          <?php elseif($game->status === 'closed'): ?>
          <div class="d-grid gap-2">
            <button class="btn btn-warning btn-sm" id="btnSettle">
              <span class="material-icons" style="font-size:15px;vertical-align:middle;">flag</span>
              ثبت نتیجه + تسویه
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ستون راست: لیست شرط‌ها -->
    <div class="col-md-7">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">شرط‌ها</h6>
          <span class="badge bg-primary"><?= number_format((int)($game->total_bets ?? 0)) ?> شرکت‌کننده</span>
        </div>
        <div class="card-body p-0">
          <?php if(empty($bets)): ?>
            <div class="text-center py-4 text-muted">هنوز شرطی ثبت نشده.</div>
          <?php else: ?>
          <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
            <table class="table table-sm mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>کاربر</th>
                  <th>پیش‌بینی</th>
                  <th>مبلغ (USDT)</th>
                  <th>وضعیت</th>
                  <th>پاداش (USDT)</th>
                  <th>تاریخ</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($bets as $b):
                $predColors = ['home'=>'primary','away'=>'success','draw'=>'secondary'];
                $statusColors = ['pending'=>'secondary','won'=>'success','lost'=>'danger','refunded'=>'warning'];
                $statusLabels = ['pending'=>'در انتظار','won'=>'برنده','lost'=>'بازنده','refunded'=>'برگشت'];
              ?>
              <tr>
                <td>
                  <div style="font-size:13px;"><?= e($b->full_name ?? 'ناشناس') ?></div>
                  <small class="text-muted"><?= e($b->email ?? '') ?></small>
                </td>
                <td>
                  <span class="badge bg-<?= $predColors[$b->prediction] ?? 'secondary' ?>">
                    <?= $predMap[$b->prediction] ?? $b->prediction ?>
                  </span>
                </td>
                <td class="fw-bold"><?= number_format((float)$b->amount_usdt, 4) ?></td>
                <td>
                  <span class="badge bg-<?= $statusColors[$b->status] ?? 'secondary' ?>">
                    <?= $statusLabels[$b->status] ?? $b->status ?>
                  </span>
                </td>
                <td>
                  <?php if(isset($b->payout_usdt) && (float)$b->payout_usdt > 0): ?>
                    <span class="text-success fw-bold"><?= number_format((float)$b->payout_usdt, 4) ?></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td style="font-size:11px;"><?= e(substr((string)($b->created_at ?? ''), 0, 16)) ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal ثبت نتیجه -->
<div class="modal fade" id="settleModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">ثبت نتیجه و تسویه</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-grid gap-2">
          <button class="btn btn-primary btn-result" data-result="home">
            🏠 <?= e($game->team_home) ?> برنده شد
          </button>
          <button class="btn btn-secondary btn-result" data-result="draw">🤝 مساوی</button>
          <button class="btn btn-success btn-result" data-result="away">
            ✈️ <?= e($game->team_away) ?> برنده شد
          </button>
        </div>
        <p class="text-danger small mt-3 mb-0">
          <span class="material-icons" style="font-size:13px;vertical-align:middle;">warning</span>
          پس از تأیید، جوایز فوری پرداخت می‌شوند و قابل بازگشت نیست.
        </p>
      </div>
    </div>
  </div>
</div>

<script>
const gameId = <?= (int)$game->id ?>;
function csrf() { return document.querySelector('meta[name=csrf-token]')?.content ?? ''; }

function showToast(msg, type='success') {
  const el = document.createElement('div');
  el.className = `alert alert-${type} position-fixed top-0 end-0 m-3`;
  el.style.zIndex = 9999;
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

document.getElementById('btnSettle')?.addEventListener('click', () => {
  new bootstrap.Modal(document.getElementById('settleModal')).show();
});

document.querySelectorAll('.btn-result').forEach(btn => {
  btn.addEventListener('click', function() {
    if (!confirm('تأیید می‌کنید؟ این عملیات غیر قابل بازگشت است.')) return;
    this.disabled = true;
    fetch(`/admin/prediction/${gameId}/settle`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify({ result: this.dataset.result })
    })
    .then(r => r.json())
    .then(d => {
      bootstrap.Modal.getInstance(document.getElementById('settleModal'))?.hide();
      showToast(d.message, d.success ? 'success' : 'danger');
      if (d.success) setTimeout(() => location.reload(), 2000);
    })
    .catch(() => showToast('خطای شبکه', 'danger'))
    .finally(() => this.disabled = false);
  });
});

document.getElementById('btnCancel')?.addEventListener('click', function() {
  if (!confirm('لغو این بازی و برگشت وجه به همه شرط‌بندان؟')) return;
  fetch(`/admin/prediction/${gameId}/cancel`, {
    method: 'POST', headers: { 'X-CSRF-Token': csrf() }
  })
  .then(r => r.json())
  .then(d => {
    showToast(d.message, d.success ? 'success' : 'danger');
    if (d.success) setTimeout(() => location.reload(), 2000);
  });
});

document.getElementById('btnCloseBetting')?.addEventListener('click', function() {
  if (!confirm('شرط‌گیری جدید برای این بازی بسته شود؟')) return;
  fetch(`/admin/prediction/${gameId}/close-betting`, {
    method: 'POST', headers: { 'X-CSRF-Token': csrf() }
  })
  .then(r => r.json())
  .then(d => {
    showToast(d.message, d.success ? 'success' : 'danger');
    if (d.success) setTimeout(() => location.reload(), 1500);
  });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
