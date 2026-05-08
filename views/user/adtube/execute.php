<?php $layout='user'; ob_start();
$ytUrl = $task->youtube_url ?? $task->target_url ?? '';
preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $ytUrl, $m);
$videoId = $m[1] ?? null;
$duration = (int)($task->watch_duration_seconds ?? 30);
?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-danger">play_circle</span> تماشای ویدیو</h4>
    <p class="text-muted mb-0" style="font-size:12px;"><?= e($task->title ?? '') ?></p>
  </div>
  <a href="<?= url('/adtube') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<div class="row mt-3 justify-content-center">
  <div class="col-md-10">
    <div class="card">
      <div class="card-body">

        <?php if($videoId): ?>
        <div class="ratio ratio-16x9 mb-3">
          <iframe id="ytFrame"
            src="https://www.youtube.com/embed/<?= e($videoId) ?>?autoplay=1&rel=0"
            allow="autoplay; encrypted-media" allowfullscreen></iframe>
        </div>
        <?php elseif($ytUrl): ?>
        <div class="alert alert-info">
          <a href="<?= e($ytUrl) ?>" target="_blank" class="btn btn-danger mb-3">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">open_in_new</span> باز کردن ویدیو در یوتیوب
          </a>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="fw-bold"><?= e($task->title ?? '') ?></div>
            <small class="text-muted"><?= e($task->description ?? '') ?></small>
          </div>
          <div class="text-center">
            <div class="fw-bold text-success fs-5"><?= number_format($task->reward_amount ?? $task->reward ?? 0) ?> تومان</div>
            <small class="text-muted">پاداش</small>
          </div>
        </div>

        <div class="alert alert-warning d-flex align-items-center gap-2" id="waitAlert">
          <span class="material-icons">timer</span>
          <div>برای دریافت پاداش، ویدیو را حداقل <strong><?= $duration ?> ثانیه</strong> تماشا کنید.
            زمان باقی‌مانده: <strong id="countdown"><?= $duration ?></strong> ثانیه</div>
        </div>

        <form method="POST" action="<?= url("/adtube/{$execution->id}/submit") ?>" id="submitForm">
          <?= csrf_field() ?>
          <input type="hidden" name="watched_seconds" id="watchedSeconds" value="0">
          <button type="submit" class="btn btn-success w-100" id="btnSubmit" disabled>
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">check_circle</span>
            تایید تماشا و دریافت پاداش
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
let remaining = <?= $duration ?>;
let watched = 0;
const timer = setInterval(() => {
  remaining--;
  watched++;
  document.getElementById('countdown').textContent = Math.max(0, remaining);
  document.getElementById('watchedSeconds').value = watched;
  if(remaining <= 0) {
    clearInterval(timer);
    document.getElementById('waitAlert').className = 'alert alert-success d-flex align-items-center gap-2';
    document.getElementById('waitAlert').innerHTML = '<span class="material-icons">check_circle</span><div>تماشا کامل شد! روی دکمه زیر کلیک کنید.</div>';
    document.getElementById('btnSubmit').disabled = false;
  }
}, 1000);
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>

