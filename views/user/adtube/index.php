<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">play_circle</span> Adtube — کسب درآمد از یوتیوب</h4>
    <p class="text-muted mb-0" style="font-size:12px;">ویدیوها را تماشا کنید و درآمد کسب کنید</p>
  </div>
  <a href="<?= url('/adtube/history') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">history</span> تاریخچه
  </a>
</div>

<?php if(!empty($stats)): ?>
<div class="row mt-3 mb-2">
  <div class="col-4 col-md-3">
    <div class="card text-center py-2">
      <div class="fw-bold text-primary fs-5"><?= number_format($stats['total_watched'] ?? 0) ?></div>
      <div class="text-muted" style="font-size:11px;">ویدیو تماشا شده</div>
    </div>
  </div>
  <div class="col-4 col-md-3">
    <div class="card text-center py-2">
      <div class="fw-bold text-success fs-5"><?= number_format($stats['total_earned'] ?? 0) ?></div>
      <div class="text-muted" style="font-size:11px;">تومان درآمد</div>
    </div>
  </div>
  <div class="col-4 col-md-3">
    <div class="card text-center py-2">
      <div class="fw-bold text-warning fs-5"><?= number_format($stats['pending_count'] ?? 0) ?></div>
      <div class="text-muted" style="font-size:11px;">در انتظار تایید</div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if(empty($tasks)): ?>
<div class="card mt-3">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">play_circle_outline</span>
    <h5 class="mt-3 text-muted">در حال حاضر ویدیویی موجود نیست</h5>
    <p class="text-muted small">بعداً مراجعه کنید. ویدیوهای جدید اضافه می‌شوند.</p>
  </div>
</div>
<?php else: ?>
<div class="row mt-3">
<?php foreach($tasks as $task): ?>
<div class="col-md-6 col-lg-4 mb-3">
  <div class="card h-100">
    <div class="card-body d-flex flex-column">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span class="badge bg-danger">YouTube</span>
        <span class="fw-bold text-success"><?= number_format($task->reward_amount ?? $task->reward ?? 0) ?> تومان</span>
      </div>
      <?php if(!empty($task->youtube_url ?? $task->target_url ?? '')): ?>
      <div class="mb-2 text-center">
        <?php
          $ytUrl = $task->youtube_url ?? $task->target_url ?? '';
          preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $ytUrl, $m);
          $videoId = $m[1] ?? null;
        ?>
        <?php if($videoId): ?>
        <img src="https://img.youtube.com/vi/<?= e($videoId) ?>/mqdefault.jpg"
             class="rounded" style="width:100%;max-height:130px;object-fit:cover;" alt="thumbnail">
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <h6 class="fw-bold mb-1"><?= e(mb_substr($task->title ?? '', 0, 60)) ?></h6>
      <p class="text-muted small flex-grow-1"><?= e(mb_substr($task->description ?? '', 0, 80)) ?></p>
      <div class="d-flex justify-content-between small text-muted mb-3">
        <span><span class="material-icons" style="font-size:13px;vertical-align:middle;">timer</span>
          <?= $task->watch_duration_seconds ?? 30 ?> ثانیه
        </span>
        <span><?= number_format($task->remaining_slots ?? $task->slots_remaining ?? 0) ?> جای خالی</span>
      </div>
      <button class="btn btn-danger btn-sm mt-auto btn-start-adtube" data-id="<?= e($task->id) ?>">
        <span class="material-icons" style="font-size:16px;vertical-align:middle;">play_arrow</span> شروع تماشا
      </button>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-start-adtube').forEach(btn => {
  btn.addEventListener('click', function() {
    const id = this.dataset.id;
    this.disabled = true; this.innerHTML = '<span class="material-icons" style="font-size:14px;vertical-align:middle;">hourglass_top</span> درحال پردازش...';
    fetch('/adtube/start', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''},
      body: JSON.stringify({ad_id: id})
    }).then(r=>r.json()).then(d=>{
      if(d.success && d.execution_id) {
        location.href = `/adtube/${d.execution_id}/execute`;
      } else {
        alert(d.message || 'خطا در شروع'); this.disabled = false;
        this.innerHTML = '<span class="material-icons" style="font-size:16px;vertical-align:middle;">play_arrow</span> شروع تماشا';
      }
    });
  });
});
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>
