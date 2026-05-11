<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">smart_display</span> تبلیغات Adtube من</h4>
    <p class="text-muted mb-0" style="font-size:12px;">ویدیوهای یوتیوبی که برای تبلیغ ثبت کرده‌اید</p>
  </div>
  <a href="<?= url('/adtube/ads/create') ?>" class="btn btn-danger btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> تبلیغ جدید
  </a>
</div>

<?php if(empty($ads)): ?>
<div class="card mt-4">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">smart_display</span>
    <h5 class="mt-3 text-muted">تبلیغ ویدیویی ندارید</h5>
    <p class="text-muted small">ویدیوی یوتیوب خود را ثبت کنید تا کاربران تماشا کنند.</p>
    <a href="<?= url('/adtube/ads/create') ?>" class="btn btn-danger mt-2">ثبت اولین تبلیغ</a>
  </div>
</div>
<?php else: ?>
<div class="row mt-3">
<?php foreach($ads as $ad): ?>
<?php
  $sc = ['pending'=>'warning','active'=>'success','paused'=>'secondary','rejected'=>'danger','completed'=>'primary'];
  $sl = ['pending'=>'در انتظار','active'=>'فعال','paused'=>'متوقف','rejected'=>'رد شده','completed'=>'تکمیل'];
  $st = $ad->status ?? 'pending';
  $ytUrl = $ad->youtube_url ?? $ad->target_url ?? '';
  preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $ytUrl, $m);
  $videoId = $m[1] ?? null;
?>
<div class="col-md-6 mb-3">
  <div class="card h-100">
    <?php if($videoId): ?>
    <img src="https://img.youtube.com/vi/<?= e($videoId) ?>/mqdefault.jpg"
         class="card-img-top" style="height:130px;object-fit:cover;" alt="thumbnail">
    <?php endif; ?>
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <h6 class="fw-bold mb-0"><?= e(mb_substr($ad->title ?? '—', 0, 50)) ?></h6>
        <span class="badge bg-<?= $sc[$st] ?>"><?= $sl[$st] ?></span>
      </div>
      <hr class="my-2">
      <div class="row text-center small">
        <div class="col-4">
          <div class="fw-bold text-primary"><?= number_format($ad->completed_slots ?? 0) ?></div>
          <div class="text-muted">تماشا شده</div>
        </div>
        <div class="col-4">
          <div class="fw-bold text-warning"><?= number_format($ad->max_slots ?? 0) ?></div>
          <div class="text-muted">کل هدف</div>
        </div>
        <div class="col-4">
          <div class="fw-bold text-success"><?= number_format($ad->reward_per_user ?? $ad->reward ?? 0) ?></div>
          <div class="text-muted">تومان/نفر</div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <a href="<?= url("/adtube/ads/{$ad->id}") ?>" class="btn btn-outline-secondary btn-sm flex-fill">جزئیات</a>
        <?php if($st === 'active'): ?>
        <button class="btn btn-outline-warning btn-sm flex-fill btn-toggle-adtube" data-id="<?= e($ad->id) ?>" data-action="pause">توقف</button>
        <?php elseif($st === 'paused'): ?>
        <button class="btn btn-outline-success btn-sm flex-fill btn-toggle-adtube" data-id="<?= e($ad->id) ?>" data-action="resume">ادامه</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php if(($page ?? 1) > 1 || count($ads) >= 20): ?>
<div class="d-flex justify-content-center mt-3">
  <nav><ul class="pagination pagination-sm">
    <?php if($page > 1): ?><li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>">قبلی</a></li><?php endif; ?>
    <li class="page-item active"><a class="page-link" href="#"><?= $page ?></a></li>
    <?php if(count($ads) >= 20): ?><li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>">بعدی</a></li><?php endif; ?>
  </ul></nav>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-toggle-adtube').forEach(btn => {
  btn.addEventListener('click', function() {
    const action = this.dataset.action;
    fetch(`/adtube/ads/${this.dataset.id}/${action}`, {
      method:'POST', headers:{'X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''}
    }).then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.message||'خطا'); });
  });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
