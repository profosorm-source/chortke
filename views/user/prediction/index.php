<?php ob_start(); ?>
<?php
$title  = $title  ?? 'پیش بینی';
$layout = 'user';
ob_start();
?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1">
      <i class="material-icons text-primary" style="vertical-align:middle;">sports_soccer</i>
      پیش‌بینی بازی‌های ورزشی
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">
      شرط‌بندی با USDT · استخر مشترک · برندگان سهم خود را دریافت می‌کنند
    </p>
  </div>
  <a href="<?= url('/prediction/my-bets') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="material-icons" style="font-size:16px;vertical-align:middle;">list_alt</i>
    پیش‌بینی‌های من
  </a>
</div>

<?php if(empty($games)): ?>
  <div class="alert alert-info text-center mt-4">
    <i class="material-icons" style="font-size:36px;display:block;margin-bottom:8px;">sports_soccer</i>
    در حال حاضر بازی باز برای پیش‌بینی وجود ندارد.<br>
    <small class="text-muted">لطفاً بعداً مراجعه کنید.</small>
  </div>
<?php else: ?>
<div class="row mt-3">
  <?php foreach($games as $game):
    $pool  = (float)($game->total_pool ?? 0);
    $ph    = (float)($game->pool_home  ?? 0);
    $pa    = (float)($game->pool_away  ?? 0);
    $pd    = (float)($game->pool_draw  ?? 0);
    $pct   = fn($v) => $pool > 0 ? round($v / $pool * 100) : 33;
    $hasBet = !empty($userBets[(int)$game->id]);

    $sportIcons = [
      'football'=>'sports_soccer','basketball'=>'sports_basketball',
      'tennis'=>'sports_tennis','volleyball'=>'sports_volleyball',
      'other'=>'sports','baseball'=>'sports_baseball',
    ];
    $icon = $sportIcons[$game->sport_type ?? ''] ?? 'sports';
  ?>
  <div class="col-md-6 col-xl-4 mb-4">
    <div class="card h-100 <?= $hasBet ? 'border-success' : '' ?>">
      <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="badge bg-info">
          <i class="material-icons" style="font-size:13px;vertical-align:middle;"><?= $icon ?></i>
          <?= e($game->sport_type ?? 'football') ?>
        </span>
        <?php if($hasBet): ?>
          <span class="badge bg-success">✓ شرکت کرده‌اید</span>
        <?php else: ?>
          <span class="badge bg-warning text-dark">
            <?php
              $diff = strtotime($game->bet_deadline) - time();
              if ($diff < 3600) echo 'کمتر از ' . ceil($diff/60) . ' دقیقه';
              elseif ($diff < 86400) echo ceil($diff/3600) . ' ساعت مانده';
              else echo ceil($diff/86400) . ' روز مانده';
            ?>
          </span>
        <?php endif; ?>
      </div>

      <div class="card-body">
        <h6 class="fw-bold text-center mb-3"><?= e($game->title) ?></h6>

        <!-- تیم‌ها -->
        <div class="d-flex justify-content-around align-items-center mb-3">
          <div class="text-center">
            <div class="fw-bold"><?= e($game->team_home) ?></div>
            <small class="text-muted">خانه</small>
          </div>
          <span class="badge bg-secondary px-3 py-2">VS</span>
          <div class="text-center">
            <div class="fw-bold"><?= e($game->team_away) ?></div>
            <small class="text-muted">مهمان</small>
          </div>
        </div>

        <!-- آمار استخر -->
        <div class="row text-center border-top border-bottom py-2 mb-3 g-0">
          <div class="col-4">
            <div class="fw-bold text-success"><?= number_format($pool, 2) ?></div>
            <small class="text-muted">USDT استخر</small>
          </div>
          <div class="col-4 border-start border-end">
            <div class="fw-bold"><?= number_format((int)($game->total_bets ?? 0)) ?></div>
            <small class="text-muted">شرکت‌کننده</small>
          </div>
          <div class="col-4">
            <div class="fw-bold"><?= number_format((float)$game->min_bet_usdt, 0) ?>–<?= number_format((float)$game->max_bet_usdt, 0) ?></div>
            <small class="text-muted">USDT</small>
          </div>
        </div>

        <!-- توزیع شرط‌ها -->
        <div class="mb-3">
          <div class="d-flex justify-content-between" style="font-size:11px;color:#888;">
            <span>🏠 خانه <?= $pct($ph) ?>٪</span>
            <span>🤝 مساوی <?= $pct($pd) ?>٪</span>
            <span>✈️ مهمان <?= $pct($pa) ?>٪</span>
          </div>
          <div class="progress mt-1" style="height:6px;border-radius:3px;">
            <div class="progress-bar bg-primary"  style="width:<?= $pct($ph) ?>%"></div>
            <div class="progress-bar bg-secondary" style="width:<?= $pct($pd) ?>%"></div>
            <div class="progress-bar bg-success"  style="width:<?= $pct($pa) ?>%"></div>
          </div>
        </div>

        <a href="<?= url('/prediction/' . (int)$game->id) ?>"
           class="btn <?= $hasBet ? 'btn-outline-success' : 'btn-primary' ?> w-100 btn-sm">
          <?= $hasBet ? '✓ مشاهده شرط من' : 'شرکت در پیش‌بینی' ?>
        </a>
      </div>

      <div class="card-footer text-muted py-1" style="font-size:11px;">
        <i class="material-icons" style="font-size:12px;vertical-align:middle;">schedule</i>
        بازی: <?= e(substr((string)$game->match_date, 0, 16)) ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
