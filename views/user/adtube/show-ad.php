<?php $layout='user'; ob_start();
$ytUrl = $ad->youtube_url ?? $ad->target_url ?? '';
preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $ytUrl, $m);
$videoId = $m[1] ?? null;
?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-danger">smart_display</span> جزئیات تبلیغ Adtube</h4>
    <p class="text-muted mb-0" style="font-size:12px;"><?= e($ad->title ?? '') ?></p>
  </div>
  <a href="<?= url('/adtube/ads') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<?php if(!$ad): ?>
  <div class="alert alert-danger mt-3">تبلیغ یافت نشد.</div>
<?php else: ?>
<?php
  $sc = ['pending'=>'warning','active'=>'success','paused'=>'secondary','rejected'=>'danger','completed'=>'primary'];
  $sl = ['pending'=>'در انتظار تایید','active'=>'فعال','paused'=>'متوقف','rejected'=>'رد شده','completed'=>'تکمیل'];
  $st = $ad->status ?? 'pending';
?>
<div class="row mt-3">
  <div class="col-md-7">
    <div class="card mb-3">
      <?php if($videoId): ?>
      <div class="ratio ratio-16x9">
        <iframe src="https://www.youtube.com/embed/<?= e($videoId) ?>" allowfullscreen></iframe>
      </div>
      <?php endif; ?>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <h6 class="fw-bold"><?= e($ad->title) ?></h6>
          <span class="badge bg-<?= $sc[$st] ?>"><?= $sl[$st] ?></span>
        </div>
        <?php if($ad->description ?? ''): ?>
        <p class="text-muted small"><?= e($ad->description) ?></p>
        <?php endif; ?>
        <table class="table table-sm table-bordered mt-2">
          <tr><th>لینک ویدیو</th><td><a href="<?= e($ytUrl) ?>" target="_blank" class="text-truncate d-inline-block" style="max-width:250px;"><?= e($ytUrl) ?></a></td></tr>
          <tr><th>مدت تماشا</th><td><?= e($ad->watch_duration_seconds ?? 30) ?> ثانیه</td></tr>
          <tr><th>پاداش/نفر</th><td class="text-success fw-bold"><?= number_format($ad->reward_per_user ?? $ad->reward ?? 0) ?> تومان</td></tr>
          <tr><th>هدف</th><td><?= number_format($ad->max_slots ?? 0) ?> بازدید</td></tr>
          <?php if($ad->rejection_reason ?? ''): ?>
          <tr><th>دلیل رد</th><td class="text-danger"><?= e($ad->rejection_reason) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card mb-3">
      <div class="card-header"><h6 class="mb-0">آمار</h6></div>
      <div class="card-body">
        <?php $done = $ad->completed_slots ?? ($stats['completed'] ?? 0); $total = $ad->max_slots ?? 1; ?>
        <div class="text-center mb-3">
          <div class="fs-2 fw-bold text-primary"><?= number_format($done) ?></div>
          <div class="text-muted">بازدید تایید شده از <?= number_format($total) ?></div>
        </div>
        <div class="progress mb-3" style="height:10px;">
          <div class="progress-bar bg-success" style="width:<?= min(100, ($done / max(1,$total))*100) ?>%"></div>
        </div>
        <div class="d-flex justify-content-between small text-muted">
          <span>در انتظار: <?= number_format($stats['pending'] ?? 0) ?></span>
          <span>رد شده: <?= number_format($stats['rejected'] ?? 0) ?></span>
        </div>
        <hr>
        <div class="text-center">
          <div class="fw-bold text-success"><?= number_format(($done * ($ad->reward_per_user ?? $ad->reward ?? 0))) ?> تومان</div>
          <div class="text-muted small">هزینه پرداخت شده</div>
        </div>
      </div>
    </div>

    <?php if(in_array($st, ['active','paused'])): ?>
    <div class="card">
      <div class="card-body d-grid gap-2">
        <?php if($st === 'active'): ?>
        <form method="POST" action="<?= url("/adtube/ads/{$ad->id}/pause") ?>">
          <?= csrf_field() ?>
          <button class="btn btn-warning w-100">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">pause</span> توقف موقت
          </button>
        </form>
        <?php else: ?>
        <form method="POST" action="<?= url("/adtube/ads/{$ad->id}/resume") ?>">
          <?= csrf_field() ?>
          <button class="btn btn-success w-100">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">play_arrow</span> ادامه
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>
