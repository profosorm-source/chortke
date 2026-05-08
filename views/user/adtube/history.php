<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">history</span> تاریخچه Adtube</h4>
    <p class="text-muted mb-0" style="font-size:12px;">ویدیوهایی که تماشا کرده‌اید</p>
  </div>
  <a href="<?= url('/adtube') ?>" class="btn btn-outline-primary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">play_circle</span> ویدیوهای موجود
  </a>
</div>

<?php if(empty($history)): ?>
<div class="card mt-4">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">history</span>
    <h5 class="mt-3 text-muted">تاریخچه‌ای وجود ندارد</h5>
    <a href="<?= url('/adtube') ?>" class="btn btn-primary mt-2">شروع تماشا</a>
  </div>
</div>
<?php else: ?>
<div class="card mt-3">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>ویدیو</th>
            <th>پاداش</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($history as $h): ?>
          <tr>
            <td>
              <div class="fw-bold"><?= e(mb_substr($h->ad_title ?? $h->title ?? '—', 0, 50)) ?></div>
              <small class="text-muted"><?= e($h->ad_type ?? 'YouTube') ?></small>
            </td>
            <td class="text-success fw-bold"><?= number_format($h->reward_amount ?? $h->earned ?? 0) ?> تومان</td>
            <td>
              <?php
                $sc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','completed'=>'success'];
                $sl = ['pending'=>'در انتظار','approved'=>'تایید ✓','rejected'=>'رد شد','completed'=>'تایید ✓'];
                $st = $h->status ?? 'pending';
              ?>
              <span class="badge bg-<?= $sc[$st] ?? 'secondary' ?>"><?= $sl[$st] ?? $st ?></span>
            </td>
            <td style="font-size:12px;"><?= e(substr($h->created_at ?? '', 0, 16)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if(($page ?? 1) > 1 || count($history) >= 20): ?>
<div class="d-flex justify-content-center mt-3">
  <nav><ul class="pagination pagination-sm">
    <?php if($page > 1): ?>
    <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>">قبلی</a></li>
    <?php endif; ?>
    <li class="page-item active"><a class="page-link" href="#"><?= $page ?></a></li>
    <?php if(count($history) >= 20): ?>
    <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>">بعدی</a></li>
    <?php endif; ?>
  </ul></nav>
</div>
<?php endif; ?>
<?php endif; ?>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
