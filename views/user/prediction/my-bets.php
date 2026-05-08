<?php ob_start();
$predMap   = ['home' => 'خانه', 'away' => 'مهمان', 'draw' => 'مساوی'];
$resultMap = ['home' => 'خانه برد', 'away' => 'مهمان برد', 'draw' => 'مساوی'];
$statusMap = ['pending' => 'در انتظار', 'won' => 'برنده 🎉', 'lost' => 'بازنده', 'refunded' => 'برگشت داده شد'];
$statusColor = ['pending' => 'secondary', 'won' => 'success', 'lost' => 'danger', 'refunded' => 'warning'];
?>

<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1">
      <i class="material-icons text-primary" style="vertical-align:middle;">list_alt</i>
      پیش‌بینی‌های من
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">
      <?= number_format($total) ?> شرط‌بندی در مجموع
    </p>
  </div>
  <a href="<?= url('/prediction') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="material-icons" style="font-size:16px;vertical-align:middle;">sports_soccer</i>
    بازی‌های باز
  </a>
</div>

<?php if(empty($bets)): ?>
  <div class="alert alert-info text-center mt-4">
    <i class="material-icons" style="font-size:36px;display:block;margin-bottom:8px;">sports_soccer</i>
    هنوز در هیچ بازی پیش‌بینی ثبت نکرده‌اید.<br>
    <a href="<?= url('/prediction') ?>" class="btn btn-primary btn-sm mt-2">مشاهده بازی‌های باز</a>
  </div>
<?php else: ?>

<!-- آمار خلاصه -->
<?php
  $totalBet    = array_sum(array_column($bets, 'amount_usdt'));
  $totalPayout = array_sum(array_column(array_filter($bets, fn($b) => $b->status === 'won'), 'payout_usdt'));
  $wonCount    = count(array_filter($bets, fn($b) => $b->status === 'won'));
  $lostCount   = count(array_filter($bets, fn($b) => $b->status === 'lost'));
?>
<div class="row g-3 mt-1 mb-4">
  <div class="col-6 col-md-3">
    <div class="card text-center py-2">
      <div class="fw-bold fs-5"><?= number_format($total) ?></div>
      <small class="text-muted">کل شرط‌ها</small>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2">
      <div class="fw-bold fs-5 text-success"><?= number_format($wonCount) ?></div>
      <small class="text-muted">برنده شده</small>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2">
      <div class="fw-bold fs-5"><?= number_format($totalBet, 2) ?></div>
      <small class="text-muted">کل شرط (USDT)</small>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2">
      <div class="fw-bold fs-5 <?= $totalPayout >= $totalBet ? 'text-success' : 'text-danger' ?>">
        <?= number_format($totalPayout, 2) ?>
      </div>
      <small class="text-muted">کل دریافتی (USDT)</small>
    </div>
  </div>
</div>

<!-- لیست شرط‌ها -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>بازی</th>
            <th>پیش‌بینی من</th>
            <th>مبلغ (USDT)</th>
            <th>نتیجه بازی</th>
            <th>وضعیت</th>
            <th>دریافتی (USDT)</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($bets as $b): ?>
        <tr>
          <td>
            <a href="<?= url('/prediction/' . (int)$b->game_id) ?>" class="text-decoration-none fw-bold" style="font-size:13px;">
              <?= e($b->game_title ?? 'بازی #' . $b->game_id) ?>
            </a>
            <br>
            <small class="text-muted">
              <?= e($b->team_home ?? '') ?>
              <span class="text-muted mx-1">vs</span>
              <?= e($b->team_away ?? '') ?>
            </small>
            <?php if(!empty($b->sport_type)): ?>
              <span class="badge bg-light text-dark ms-1" style="font-size:10px;"><?= e($b->sport_type) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge bg-primary">
              <?= $predMap[$b->prediction] ?? e($b->prediction) ?>
            </span>
          </td>
          <td class="fw-bold"><?= number_format((float)$b->amount_usdt, 4) ?></td>
          <td>
            <?php if($b->game_result): ?>
              <span class="badge bg-info"><?= $resultMap[$b->game_result] ?? e($b->game_result) ?></span>
            <?php elseif(($b->game_status ?? '') === 'cancelled'): ?>
              <span class="badge bg-danger">لغو شده</span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge bg-<?= $statusColor[$b->status] ?? 'secondary' ?>">
              <?= $statusMap[$b->status] ?? e($b->status) ?>
            </span>
          </td>
          <td>
            <?php if($b->status === 'won' && isset($b->payout_usdt) && (float)$b->payout_usdt > 0): ?>
              <span class="text-success fw-bold"><?= number_format((float)$b->payout_usdt, 4) ?></span>
              <?php
                $roi = (float)$b->amount_usdt > 0
                    ? (((float)$b->payout_usdt / (float)$b->amount_usdt) - 1) * 100
                    : 0;
              ?>
              <br><small class="text-success">+<?= number_format($roi, 1) ?>٪</small>
            <?php elseif($b->status === 'refunded'): ?>
              <span class="text-warning"><?= number_format((float)$b->amount_usdt, 4) ?></span>
              <br><small class="text-muted">برگشت</small>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11px;color:#888;">
            <?= e(substr((string)($b->created_at ?? ''), 0, 16)) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- صفحه‌بندی -->
<?php if($totalPages > 1): ?>
<nav class="d-flex justify-content-center mt-3">
  <ul class="pagination pagination-sm">
    <?php if($page > 1): ?>
      <li class="page-item">
        <a class="page-link" href="?page=<?= $page - 1 ?>">قبلی</a>
      </li>
    <?php endif; ?>

    <?php for($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>

    <?php if($page < $totalPages): ?>
      <li class="page-item">
        <a class="page-link" href="?page=<?= $page + 1 ?>">بعدی</a>
      </li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php $content = ob_get_clean(); include view_path('layouts.user'); ?>
