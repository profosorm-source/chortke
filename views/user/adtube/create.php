<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-danger">smart_display</span> ثبت تبلیغ ویدیوی یوتیوب</h4>
    <p class="text-muted mb-0" style="font-size:12px;">ویدیوی یوتیوب خود را تبلیغ کنید — کاربران تماشا می‌کنند و درآمد کسب می‌کنند</p>
  </div>
  <a href="<?= url('/adtube/ads') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<div class="row mt-3 justify-content-center">
  <div class="col-md-8">
    <div class="alert alert-info small">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">info</span>
      کاربران ویدیو شما را برای مدت مشخص تماشا می‌کنند. برای هر تماشا، پاداش از بودجه شما پرداخت می‌شود.
    </div>
    <div class="card">
      <div class="card-body">
        <form method="POST" action="<?= url('/adtube/ads/store') ?>">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-bold">لینک ویدیوی یوتیوب <span class="text-danger">*</span></label>
            <input type="url" name="youtube_url" class="form-control" id="ytUrlInput"
              placeholder="https://www.youtube.com/watch?v=..." required>
            <small class="text-muted">لینک ویدیو از youtube.com یا youtu.be</small>
            <div id="ytPreview" class="mt-2 d-none">
              <div class="ratio ratio-16x9">
                <iframe id="ytFrame" src="" allowfullscreen></iframe>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">عنوان تبلیغ <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" required maxlength="200"
              placeholder="مثال: معرفی محصول جدید — تماشا کنید و جایزه بگیرید">
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">توضیحات</label>
            <textarea name="description" class="form-control" rows="2"
              placeholder="اطلاعات بیشتر درباره ویدیو..."></textarea>
          </div>

          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">مدت تماشای لازم (ثانیه) <span class="text-danger">*</span></label>
              <input type="number" name="watch_duration_seconds" class="form-control" min="15" max="600" value="30" required>
              <small class="text-muted">حداقل ۱۵، حداکثر ۶۰۰ ثانیه</small>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">پاداش هر تماشا (تومان) <span class="text-danger">*</span></label>
              <input type="number" name="reward_per_user" class="form-control" min="100" step="100" value="500" required id="rewardInput">
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">تعداد بازدید مورد نیاز <span class="text-danger">*</span></label>
              <input type="number" name="max_slots" class="form-control" min="1" max="50000" value="100" required id="slotsInput">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">بودجه کل تخمینی</label>
              <div class="form-control bg-light" id="totalBudget">۵۰٬۰۰۰ تومان</div>
            </div>
          </div>

          <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="<?= url('/adtube/ads') ?>" class="btn btn-outline-secondary">انصراف</a>
            <button type="submit" class="btn btn-danger">
              <span class="material-icons" style="font-size:16px;vertical-align:middle;">smart_display</span> ثبت تبلیغ
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// YouTube preview
document.getElementById('ytUrlInput')?.addEventListener('change', function() {
  const url = this.value;
  const m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
  if(m) {
    document.getElementById('ytFrame').src = `https://www.youtube.com/embed/${m[1]}`;
    document.getElementById('ytPreview').classList.remove('d-none');
  }
});
// Budget calc
function updateBudget() {
  const r = parseFloat(document.getElementById('rewardInput')?.value) || 0;
  const s = parseInt(document.getElementById('slotsInput')?.value) || 0;
  document.getElementById('totalBudget').textContent = (r * s).toLocaleString('fa-IR') + ' تومان';
}
document.getElementById('rewardInput')?.addEventListener('input', updateBudget);
document.getElementById('slotsInput')?.addEventListener('input', updateBudget);
updateBudget();
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
