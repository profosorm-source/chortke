<?php
$title = $title ?? 'ثبت آگهی SEO';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-seo-ad.css') ?>">

<div class="page-header">
    <h4><i class="material-icons">add_circle</i> ثبت آگهی SEO جدید</h4>
</div>

<div class="form-container">
    <form method="POST" action="<?= url('/seo-ad/store') ?>" id="adForm">
        <?= csrf_field() ?>
        
        <div class="form-section">
            <h5>اطلاعات پایه</h5>
            
            <div class="form-group">
                <label for="title">عنوان آگهی *</label>
                <input type="text" id="title" name="title" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="keyword">کلمه کلیدی *</label>
                <input type="text" id="keyword" name="keyword" class="form-control" required>
                <small class="form-text">کلمه‌ای که می‌خواهید در گوگل جستجو شود</small>
            </div>

            <div class="form-group">
                <label for="site_url">آدرس سایت هدف *</label>
                <input type="url" id="site_url" name="site_url" class="form-control" required placeholder="https://example.com">
                <small class="form-text">URL کاملی که کاربران باید آن را مشاهده کنند</small>
            </div>

            <div class="form-group">
                <label for="description">توضیحات (اختیاری)</label>
                <textarea id="description" name="description" class="form-control" rows="3"></textarea>
            </div>
        </div>

        <div class="form-section">
            <h5>تنظیمات بودجه و پرداخت</h5>
            
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="budget">بودجه کل (تومان) *</label>
                    <input type="number" id="budget" name="budget" class="form-control" 
                           min="<?= $minBudget ?>" step="1000" required value="<?= $minBudget ?>">
                    <small class="form-text">حداقل: <?= number_format($minBudget) ?> تومان</small>
                </div>

                <div class="form-group col-md-4">
                    <label for="min_payout">حداقل پرداخت (تومان) *</label>
                    <input type="number" id="min_payout" name="min_payout" class="form-control" 
                           min="<?= $minPayout ?>" max="<?= $maxPayout ?>" step="100" required value="<?= $minPayout ?>">
                    <small class="form-text">برای امتیاز پایین</small>
                </div>

                <div class="form-group col-md-4">
                    <label for="max_payout">حداکثر پرداخت (تومان) *</label>
                    <input type="number" id="max_payout" name="max_payout" class="form-control" 
                           min="<?= $minPayout ?>" max="<?= $maxPayout ?>" step="100" required value="<?= $maxPayout ?>">
                    <small class="form-text">برای امتیاز کامل</small>
                </div>
            </div>

            <div class="alert alert-info" id="budgetEstimate" style="display: none;">
                <i class="material-icons">info</i>
                <div id="estimateText"></div>
            </div>
        </div>

        <div class="form-section">
            <h5>تنظیمات کیفیت</h5>
            
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="target_duration">حداقل زمان (ثانیه)</label>
                    <input type="number" id="target_duration" name="target_duration" class="form-control" 
                           min="30" max="300" value="60">
                    <small class="form-text">زمان حضور در صفحه</small>
                </div>

                <div class="form-group col-md-4">
                    <label for="min_score">حداقل امتیاز قابل قبول</label>
                    <input type="number" id="min_score" name="min_score" class="form-control" 
                           min="20" max="80" value="40">
                    <small class="form-text">از 0 تا 100</small>
                </div>

                <div class="form-group col-md-4">
                    <label for="max_per_day">حداکثر اجرا در روز</label>
                    <input type="number" id="max_per_day" name="max_per_day" class="form-control" 
                           min="1" max="50" value="10">
                    <small class="form-text">تعداد کاربران</small>
                </div>
            </div>
        </div>

        <div class="form-section">
            <h5>تنظیمات اختیاری</h5>
            
            <div class="form-group">
                <label for="deadline">تاریخ انقضا (اختیاری)</label>
                <input type="datetime-local" id="deadline" name="deadline" class="form-control">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="material-icons">check</i> ثبت آگهی
            </button>
            <a href="<?= url('/seo-ad') ?>" class="btn btn-secondary btn-lg">انصراف</a>
        </div>
    </form>
</div>

<script>
const budgetInput = document.getElementById('budget');
const minPayoutInput = document.getElementById('min_payout');
const maxPayoutInput = document.getElementById('max_payout');
const estimateDiv = document.getElementById('budgetEstimate');
const estimateText = document.getElementById('estimateText');

function updateEstimate() {
    const budget = parseFloat(budgetInput.value) || 0;
    const minPayout = parseFloat(minPayoutInput.value) || 0;
    const maxPayout = parseFloat(maxPayoutInput.value) || 0;
    
    if (budget > 0 && minPayout > 0 && maxPayout > 0 && maxPayout > minPayout) {
        const avgPayout = (minPayout + maxPayout) / 2;
        const minUsers = Math.floor(budget / maxPayout);
        const maxUsers = Math.floor(budget / minPayout);
        const avgUsers = Math.floor(budget / avgPayout);
        
        estimateDiv.style.display = 'flex';
        estimateText.innerHTML = `
            <strong>پیش‌بینی:</strong><br>
            حداقل ${minUsers.toLocaleString()} کاربر (همه امتیاز کامل) |
            میانگین ${avgUsers.toLocaleString()} کاربر |
            حداکثر ${maxUsers.toLocaleString()} کاربر (همه امتیاز پایین)
        `;
    } else {
        estimateDiv.style.display = 'none';
    }
}

budgetInput.addEventListener('input', updateEstimate);
minPayoutInput.addEventListener('input', updateEstimate);
maxPayoutInput.addEventListener('input', updateEstimate);

// Validation
document.getElementById('adForm').addEventListener('submit', function(e) {
    const minPayout = parseFloat(minPayoutInput.value);
    const maxPayout = parseFloat(maxPayoutInput.value);
    
    if (maxPayout <= minPayout) {
        e.preventDefault();
        notyf.error('حداکثر پرداخت باید بیشتر از حداقل باشد');
        return false;
    }
});

updateEstimate();
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
