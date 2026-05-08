<?php
$layout     = 'user';
$platforms  = $platforms  ?? [];
$task_types = $task_types ?? [];
ob_start();
?>
<div class="page-header d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">add_circle</i> ثبت آگهی جدید</h4>
    <a href="<?= url('/social-ads') ?>" class="btn btn-outline-secondary btn-sm">آگهی‌های من</a>
</div>

<div class="row justify-content-center">
  <div class="col-md-8">
    <div class="card">
      <div class="card-body">
        <?= flash_message() ?>
        <form method="POST" action="<?= url('/social-ads/store') ?>">
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">پلتفرم <span class="text-danger">*</span></label>
                    <select name="platform" id="sel-platform" class="form-select" required>
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($platforms as $k => $v): ?>
                            <option value="<?= e($k) ?>"><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">تسک‌های یوتیوب در بخش جداگانه‌ای ثبت می‌شوند</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">نوع تسک <span class="text-danger">*</span></label>
                    <select name="task_type" id="sel-type" class="form-select" required>
                        <option value="">ابتدا پلتفرم را انتخاب کنید</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-bold">عنوان <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required minlength="3" maxlength="120"
                           placeholder="توضیح کوتاه از تسک">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold">توضیحات</label>
                    <textarea name="description" class="form-control" rows="3"
                              placeholder="دستورالعمل‌های دقیق برای اجراکننده..."></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">لینک هدف <span class="text-danger">*</span></label>
                    <input type="url" name="target_url" class="form-control" required
                           placeholder="https://...">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">نام‌کاربری هدف</label>
                    <div class="input-group">
                        <span class="input-group-text">@</span>
                        <input type="text" name="target_username" class="form-control"
                               placeholder="username (اختیاری)">
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">پاداش هر کاربر (تومان) <span class="text-danger">*</span></label>
                    <input type="number" name="reward" class="form-control" required min="100" step="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">تعداد کاربر <span class="text-danger">*</span></label>
                    <input type="number" name="max_slots" class="form-control" required min="1" id="inp-slots">
                </div>

                <!-- comment templates — فقط برای نوع comment -->
                <div class="col-12" id="comment-templates" style="display:none;">
                    <label class="form-label fw-bold">متن‌های پیشنهادی کامنت</label>
                    <small class="text-muted d-block mb-2">چند متن وارد کنید. سیستم به صورت تصادفی انتخاب می‌کند.</small>
                    <div id="templates-list">
                        <div class="input-group mb-2">
                            <input type="text" name="comment_templates[]" class="form-control" placeholder="متن کامنت ۱">
                            <button type="button" class="btn btn-outline-danger btn-remove-tmpl" style="display:none;">×</button>
                        </div>
                    </div>
                    <button type="button" id="btn-add-tmpl" class="btn btn-outline-secondary btn-sm">+ افزودن متن</button>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="allow_copy_paste" id="chk-copy" value="1">
                        <label class="form-check-label" for="chk-copy">اجازه کپی-پیست متن</label>
                    </div>
                </div>

                <!-- هزینه کل -->
                <div class="col-12">
                    <div class="alert alert-info mb-0">
                        هزینه کل: <strong id="total-cost">۰</strong> تومان
                        (از کیف پول کسر می‌شود)
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="material-icons align-middle" style="font-size:16px;">publish</i>
                    ثبت و کسر از کیف پول
                </button>
                <a href="<?= url('/social-ads') ?>" class="btn btn-outline-secondary">انصراف</a>
            </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const typesByPlatform = {
    instagram: ['follow','like','comment','share'],
    telegram:  ['join_channel','join_group'],
    twitter:   ['follow','like','retweet','comment'],
    tiktok:    ['follow','like','comment','share'],
};
const typeLabels = {
    follow:'فالو', like:'لایک', comment:'کامنت', share:'اشتراک‌گذاری',
    retweet:'ریتوییت', join_channel:'عضویت در کانال', join_group:'عضویت در گروه'
};

document.getElementById('sel-platform').addEventListener('change', function () {
    const types = typesByPlatform[this.value] || [];
    const sel = document.getElementById('sel-type');
    sel.innerHTML = '<option value="">انتخاب کنید</option>';
    types.forEach(t => sel.innerHTML += `<option value="${t}">${typeLabels[t]}</option>`);
});

document.getElementById('sel-type').addEventListener('change', function () {
    document.getElementById('comment-templates').style.display =
        this.value === 'comment' ? 'block' : 'none';
});

function updateCost() {
    const reward = parseFloat(document.querySelector('[name=reward]').value) || 0;
    const slots  = parseInt(document.getElementById('inp-slots').value) || 0;
    document.getElementById('total-cost').textContent =
        new Intl.NumberFormat('fa-IR').format(reward * slots);
}
document.querySelector('[name=reward]').addEventListener('input', updateCost);
document.getElementById('inp-slots').addEventListener('input', updateCost);

document.getElementById('btn-add-tmpl').addEventListener('click', function () {
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = `<input type="text" name="comment_templates[]" class="form-control" placeholder="متن کامنت">
        <button type="button" class="btn btn-outline-danger btn-remove-tmpl">×</button>`;
    div.querySelector('.btn-remove-tmpl').addEventListener('click', () => div.remove());
    document.getElementById('templates-list').appendChild(div);
    document.querySelectorAll('.btn-remove-tmpl').forEach(b => b.style.display = 'block');
});
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
