<?php $layout='user'; ob_start();
$gradeColors = ['A'=>'success','B'=>'primary','C'=>'warning','D'=>'warning','F'=>'danger'];
?>

<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1">
      <i class="material-icons text-primary">campaign</i> ثبت سفارش تبلیغ
    </h4>
  </div>
  <a href="<?= url('/influencer/ads') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="material-icons" style="font-size:15px;vertical-align:middle;">arrow_back</i> بازگشت
  </a>
</div>

<?php if(!$profile): ?>
  <div class="alert alert-danger mt-3">اینفلوئنسر یافت نشد یا فعال نیست.</div>
<?php else: ?>

<div class="row mt-3 g-3">

  <!-- کارت اطلاعات اینفلوئنسر -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <?php if(!empty($profile->profile_image)): ?>
            <img src="<?= e($profile->profile_image) ?>" class="rounded-circle"
                 style="width:56px;height:56px;object-fit:cover;">
          <?php else: ?>
            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold"
                 style="width:56px;height:56px;font-size:20px;">
              <?= mb_strtoupper(mb_substr($profile->username, 0, 1)) ?>
            </div>
          <?php endif; ?>
          <div>
            <div class="fw-bold">@<?= e($profile->username) ?></div>
            <div class="text-muted small">
              <?php $f = (int)($profile->follower_count ?? 0);
              echo $f >= 1000000 ? round($f/1000000,1).'M' : ($f >= 1000 ? round($f/1000,1).'K' : $f); ?>
              فالوور
            </div>
            <a href="<?= e($profile->page_url ?? '#') ?>" target="_blank" class="small text-muted">
              <i class="material-icons" style="font-size:12px;vertical-align:middle;">open_in_new</i>
              مشاهده پیج
            </a>
          </div>
        </div>

        <?php if(!empty($profile->bio)): ?>
          <p class="small text-muted mb-3"><?= e($profile->bio) ?></p>
        <?php endif; ?>

        <!-- آمار رتبه -->
        <?php if($stats && $stats->total_orders > 0): ?>
        <div class="border rounded p-2 mb-3 small">
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">رتبه:</span>
            <span class="badge bg-<?= $gradeColors[$stats->grade] ?? 'secondary' ?>">
              <?= e($stats->grade) ?> — <?= e($stats->grade_label) ?>
            </span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">نرخ تکمیل:</span>
            <strong class="text-<?= $stats->completion_rate >= 80 ? 'success' : 'warning' ?>">
              <?= $stats->completion_rate ?>%
            </strong>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">نرخ اختلاف:</span>
            <strong class="text-<?= $stats->dispute_rate <= 10 ? 'success' : 'danger' ?>">
              <?= $stats->dispute_rate ?>%
            </strong>
          </div>
          <div class="d-flex justify-content-between">
            <span class="text-muted">سفارش تکمیل‌شده:</span>
            <strong><?= number_format($stats->completed_orders) ?></strong>
          </div>
        </div>
        <?php endif; ?>

        <!-- تعرفه‌ها -->
        <div class="small">
          <div class="fw-bold mb-1 text-muted">تعرفه‌ها</div>
          <?php if($profile->story_price_24h > 0): ?>
          <div class="d-flex justify-content-between border-bottom py-1">
            <span>استوری ۲۴ ساعته</span>
            <strong class="text-success"><?= number_format($profile->story_price_24h) ?></strong>
          </div>
          <?php endif; ?>
          <?php if($profile->post_price_24h > 0): ?>
          <div class="d-flex justify-content-between border-bottom py-1">
            <span>پست ۲۴ ساعته</span>
            <strong class="text-primary"><?= number_format($profile->post_price_24h) ?></strong>
          </div>
          <?php endif; ?>
          <?php if($profile->post_price_48h > 0): ?>
          <div class="d-flex justify-content-between border-bottom py-1">
            <span>پست ۴۸ ساعته</span>
            <strong class="text-primary"><?= number_format($profile->post_price_48h) ?></strong>
          </div>
          <?php endif; ?>
          <?php if($profile->post_price_72h > 0): ?>
          <div class="d-flex justify-content-between py-1">
            <span>پست ۷۲ ساعته</span>
            <strong class="text-primary"><?= number_format($profile->post_price_72h) ?></strong>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- فرم سفارش -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header"><h6 class="card-title mb-0">جزئیات سفارش</h6></div>
      <div class="card-body">
        <form method="POST" action="<?= url('/influencer/ads/store') ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="influencer_id" value="<?= (int)$profile->id ?>">

          <!-- نوع تبلیغ -->
          <div class="mb-3">
            <label class="form-label fw-bold">نوع تبلیغ <span class="text-danger">*</span></label>
            <div class="row g-2" id="orderTypeCards">
              <?php
              $types = [];
              if($profile->story_price_24h > 0)
                $types[] = ['value'=>'story','hours'=>24,'label'=>'استوری ۲۴ ساعته','icon'=>'photo_camera','price'=>$profile->story_price_24h,'color'=>'danger'];
              if($profile->post_price_24h > 0)
                $types[] = ['value'=>'post','hours'=>24,'label'=>'پست ۲۴ ساعته','icon'=>'image','price'=>$profile->post_price_24h,'color'=>'primary'];
              if($profile->post_price_48h > 0)
                $types[] = ['value'=>'post','hours'=>48,'label'=>'پست ۴۸ ساعته','icon'=>'image','price'=>$profile->post_price_48h,'color'=>'primary'];
              if($profile->post_price_72h > 0)
                $types[] = ['value'=>'post','hours'=>72,'label'=>'پست ۷۲ ساعته','icon'=>'image','price'=>$profile->post_price_72h,'color'=>'primary'];
              ?>
              <?php foreach($types as $i => $t): ?>
              <div class="col-6 col-md-3">
                <label class="order-type-card border rounded p-2 text-center d-block cursor-pointer
                               <?= $i===0 ? 'border-primary bg-primary bg-opacity-10' : '' ?>"
                       style="cursor:pointer;" onclick="selectType(this, '<?= $t['value'] ?>', <?= $t['hours'] ?>)">
                  <input type="radio" name="_type_select" style="display:none;"
                         <?= $i===0 ? 'checked' : '' ?>>
                  <i class="material-icons text-<?= $t['color'] ?>"><?= $t['icon'] ?></i>
                  <div class="small fw-bold mt-1"><?= $t['label'] ?></div>
                  <div class="small text-success fw-bold"><?= number_format($t['price']) ?></div>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="order_type" id="orderTypeInput"
                   value="<?= $types[0]['value'] ?? 'story' ?>">
            <input type="hidden" name="duration_hours" id="durationInput"
                   value="<?= $types[0]['hours'] ?? 24 ?>">
          </div>

          <!-- محاسبه قیمت -->
          <div class="alert alert-success py-2 small mb-3" id="priceAlert">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">payments</i>
            مبلغ قابل پرداخت:
            <strong id="priceDisplay"><?= number_format($types[0]['price'] ?? 0) ?></strong> تومان
            — از کیف پول کسر می‌شود
          </div>

          <!-- توضیحات / بریف -->
          <div class="mb-3">
            <label class="form-label fw-bold">توضیحات / بریف تبلیغ <span class="text-danger">*</span></label>
            <textarea name="caption" class="form-control" rows="4" required
                      placeholder="محتوایی که می‌خواهید تبلیغ شود، لینک، هشتگ‌ها و هر توضیح لازم..."></textarea>
          </div>

          <!-- لینک -->
          <div class="mb-3">
            <label class="form-label fw-bold">لینک (اختیاری)</label>
            <input type="url" name="link" class="form-control"
                   placeholder="https://... لینکی که باید در محتوا باشد">
          </div>

          <!-- فایل پیوست -->
          <div class="mb-3">
            <label class="form-label fw-bold">تصویر / فایل راهنما (اختیاری)</label>
            <input type="file" name="brief_file" class="form-control"
                   accept="image/*,.pdf,.doc,.docx">
            <div class="form-text">لوگو، تصویر محصول یا هر فایل راهنمایی</div>
          </div>

          <!-- زمان ترجیحی -->
          <div class="mb-3">
            <label class="form-label fw-bold">زمان ترجیحی انتشار (اختیاری)</label>
            <input type="datetime-local" name="preferred_publish_time" class="form-control"
                   min="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
          </div>

          <div class="alert alert-warning small">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">info</i>
            مبلغ در صندوق امانی نگه داشته می‌شود. بعد از تایید انجام توسط شما، به اینفلوئنسر پرداخت می‌شود.
          </div>

          <div class="d-flex justify-content-end gap-2 mt-3">
            <a href="<?= url('/influencer/ads') ?>" class="btn btn-outline-secondary">انصراف</a>
            <button type="submit" class="btn btn-primary">
              <i class="material-icons" style="font-size:15px;vertical-align:middle;">send</i>
              ثبت و پرداخت
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
const prices = <?= json_encode(array_values(array_map(fn($t) => ['v'=>$t['value'],'h'=>$t['hours'],'p'=>$t['price']], $types ?? []))) ?>;

function selectType(el, type, hours) {
  document.querySelectorAll('.order-type-card').forEach(c => {
    c.classList.remove('border-primary','bg-primary','bg-opacity-10');
  });
  el.classList.add('border-primary','bg-primary','bg-opacity-10');
  document.getElementById('orderTypeInput').value = type;
  document.getElementById('durationInput').value = hours;

  const match = prices.find(p => p.v === type && p.h === hours);
  if (match) {
    document.getElementById('priceDisplay').textContent =
      new Intl.NumberFormat('fa-IR').format(match.p);
  }
}
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>