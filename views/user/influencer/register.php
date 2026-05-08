<?php $layout='user'; ob_start();
$old=$session->getFlash('old')??[]; $old=is_array($old)?(object)$old:$old;
$selectedPlatform=$old->platform??($existing->platform??'instagram');
?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">how_to_reg</i> <?= $existing?'ویرایش پروفایل Influencer':'ثبت پروفایل Influencer' ?></h4>
  <a href="<?= url('/influencer') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت</a>
</div>
<div class="alert alert-info mt-3 small"><i class="material-icons" style="font-size:16px;vertical-align:middle;">info</i> پشتیبانی از اینستاگرام و تلگرام | پس از ثبت توسط مدیر بررسی می‌شود</div>
<form action="<?= url('/influencer/register') ?>" method="POST" enctype="multipart/form-data" class="mt-3">
  <?= csrf_field() ?>
  <div class="card"><div class="card-header"><h6 class="card-title mb-0">اطلاعات پایه</h6></div><div class="card-body">
    <div class="row">
      <div class="col-md-4 mb-3"><label class="form-label">پلتفرم</label>
        <select name="platform" id="platformSelect" class="form-select" onchange="switchPlatform(this.value)">
        <?php foreach($platforms as $k=>$v): ?><option value="<?= e($k) ?>" <?= $selectedPlatform===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 mb-3"><label class="form-label">نام کاربری <span class="text-danger">*</span></label><input type="text" name="username" class="form-control" value="<?= e($old->username??$existing->username??'') ?>" required></div>
      <div class="col-md-4 mb-3"><label class="form-label">تعداد فالوور/عضو</label><input type="number" name="follower_count" class="form-control" value="<?= e($old->follower_count??$existing->follower_count??0) ?>" min="0"></div>
    </div>
    <div class="row">
      <div class="col-md-8 mb-3"><label class="form-label">لینک صفحه</label><input type="url" name="page_url" class="form-control" value="<?= e($old->page_url??$existing->page_url??'') ?>" placeholder="https://..."></div>
      <div class="col-md-4 mb-3"><label class="form-label">دسته‌بندی</label>
        <select name="category" class="form-select">
        <option value="">انتخاب کنید</option>
        <?php foreach($categories as $cat): ?><option value="<?= e($cat) ?>" <?= ($old->category??$existing->category??'')===$cat?'selected':'' ?>><?= e($cat) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="mb-3"><label class="form-label">بیو / توضیحات</label><textarea name="bio" class="form-control" rows="2"><?= e($old->bio??$existing->bio??'') ?></textarea></div>
    <div class="mb-3"><label class="form-label">تصویر پروفایل</label><input type="file" name="profile_image" class="form-control" accept="image/*"></div>
  </div></div>

  <!-- قیمت‌گذاری اینستاگرام -->
  <div id="pricingInstagram" class="card mt-3 <?= $selectedPlatform==='instagram'?'':'d-none' ?>">
    <div class="card-header"><h6 class="card-title mb-0">قیمت‌گذاری — اینستاگرام</h6></div>
    <div class="card-body"><div class="row">
      <div class="col-md-3 mb-3"><label class="form-label">استوری ۲۴h</label><input type="number" name="story_price_24h" class="form-control" value="<?= e($existing->story_price_24h??0) ?>" min="0" step="0.01"></div>
      <div class="col-md-3 mb-3"><label class="form-label">پست ۲۴h</label><input type="number" name="post_price_24h" class="form-control" value="<?= e($existing->post_price_24h??0) ?>" min="0" step="0.01"></div>
      <div class="col-md-3 mb-3"><label class="form-label">پست ۴۸h</label><input type="number" name="post_price_48h" class="form-control" value="<?= e($existing->post_price_48h??0) ?>" min="0" step="0.01"></div>
      <div class="col-md-3 mb-3"><label class="form-label">پست ۷۲h</label><input type="number" name="post_price_72h" class="form-control" value="<?= e($existing->post_price_72h??0) ?>" min="0" step="0.01"></div>
    </div></div>
  </div>

  <!-- قیمت‌گذاری تلگرام -->
  <div id="pricingTelegram" class="card mt-3 <?= $selectedPlatform==='telegram'?'':'d-none' ?>">
    <div class="card-header"><h6 class="card-title mb-0">قیمت‌گذاری — تلگرام</h6></div>
    <div class="card-body"><div class="row">
      <div class="col-md-4 mb-3"><label class="form-label">پست اسپانسری</label><input type="number" name="sponsored_post_price" class="form-control" value="<?= e($existing->sponsored_post_price??0) ?>" min="0" step="0.01"></div>
      <div class="col-md-4 mb-3"><label class="form-label">پین پیام</label><input type="number" name="pin_price" class="form-control" value="<?= e($existing->pin_price??0) ?>" min="0" step="0.01"></div>
      <div class="col-md-4 mb-3"><label class="form-label">فوروارد پیام</label><input type="number" name="forward_price" class="form-control" value="<?= e($existing->forward_price??0) ?>" min="0" step="0.01"></div>
    </div></div>
  </div>

  <div class="mt-3"><button type="submit" class="btn btn-primary px-4">ذخیره پروفایل</button></div>
</form>
<script>
function switchPlatform(p){
  document.getElementById('pricingInstagram').classList.toggle('d-none',p!=='instagram');
  document.getElementById('pricingTelegram').classList.toggle('d-none',p!=='telegram');
}
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>