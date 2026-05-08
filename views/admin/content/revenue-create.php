<?php $title = 'ثبت درآمد محتوا'; $layout = 'admin'; ob_start(); ?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-content.css') ?>">


<div class="content-header">
    <h4><i class="material-icons">add_chart</i> ثبت درآمد برای: <?= e($submission->title) ?></h4>
    <a href="<?= url('/admin/content/' . $submission->id) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons">arrow_back</i> بازگشت
    </a>
</div>

<div class="card">
    <div class="card-header">
        <h5>اطلاعات درآمد</h5>
    </div>
    <div class="card-body">
        <!-- اطلاعات محتوا -->
        <div class="alert alert-info mb-4">
            <strong>محتوا:</strong> <?= e($submission->title) ?> |
            <strong>کاربر:</strong> <?= e($submission->user_name ?? '') ?> |
            <strong>پلتفرم:</strong> <?= $submission->platform === 'aparat' ? 'آپارات' : 'یوتیوب' ?>
        </div>

        <form id="revenueForm">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="period">دوره (ماه شمسی) <span class="text-danger">*</span></label>
                    <input type="text" name="period" id="period" class="form-control" dir="ltr"
                           placeholder="1404-01" maxlength="7" required>
                    <small class="form-text text-muted">فرمت: YYYY-MM شمسی</small>
                </div>
                <div class="form-group col-md-4">
                    <label for="views">تعداد بازدید <span class="text-danger">*</span></label>
                    <input type="number" name="views" id="views" class="form-control" min="0" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="total_revenue">درآمد کل (<?= setting('currency_mode', 'irt') === 'usdt' ? 'تتر' : 'تومان' ?>) <span class="text-danger">*</span></label>
                    <input type="number" name="total_revenue" id="total_revenue" class="form-control" min="0" step="0.01" required>
                </div>
            </div>

            <!-- پیش‌نمایش محاسبه -->
            <div id="calcPreview" class="calc-preview" style="display:none;">
                <h6>پیش‌نمایش محاسبه:</h6>
                <div class="calc-grid">
                    <div class="calc-item">
                        <span class="calc-label">سهم سایت (<span id="prevSitePercent">0</span>%)</span>
                        <span class="calc-value" id="prevSiteAmount">0</span>
                    </div>
                    <div class="calc-item">
                        <span class="calc-label">سهم کاربر (<span id="prevUserPercent">0</span>%)</span>
                        <span class="calc-value" id="prevUserAmount">0</span>
                    </div>
                    <div class="calc-item">
                        <span class="calc-label">مالیات (<span id="prevTaxPercent">0</span>%)</span>
                        <span class="calc-value" id="prevTaxAmount">0</span>
                    </div>
                    <div class="calc-item calc-item-highlight">
                        <span class="calc-label">خالص دریافتی کاربر</span>
                        <span class="calc-value" id="prevNetAmount">0</span>
                    </div>
                </div>
            </div>

            <div class="form-actions mt-4">
                <button type="submit" id="submitBtn" class="btn btn-primary">
                    <i class="material-icons">save</i> ثبت درآمد
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('revenueForm');
    const totalInput = document.getElementById('total_revenue');
    const preview = document.getElementById('calcPreview');
    const sitePercent = <?= (float)($settings['site_share_percent'] ?? 40) ?>;
    const taxPercent = <?= (float)($settings['tax_percent'] ?? 9) ?>;
    const userPercent = 100 - sitePercent;

    document.getElementById('prevSitePercent').textContent = sitePercent;
    document.getElementById('prevUserPercent').textContent = userPercent;
    document.getElementById('prevTaxPercent').textContent = taxPercent;

    totalInput.addEventListener('input', function() {
        const total = parseFloat(this.value) || 0;
        if (total > 0) {
            preview.style.display = 'block';
            const siteAmount = Math.round(total * sitePercent / 100);
            const userAmount = Math.round(total * userPercent / 100);
            const taxAmount = Math.round(userAmount * taxPercent / 100);
            const netAmount = userAmount - taxAmount;

            document.getElementById('prevSiteAmount').textContent = siteAmount.toLocaleString();
            document.getElementById('prevUserAmount').textContent = userAmount.toLocaleString();
            document.getElementById('prevTaxAmount').textContent = taxAmount.toLocaleString();
            document.getElementById('prevNetAmount').textContent = netAmount.toLocaleString();
        } else {
            preview.style.display = 'none';
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;

        const formData = new FormData(form);
        const data = {};
        formData.forEach((v, k) => data[k] = v);

        fetch('<?= url('/admin/content/' . $submission->id . '/revenue/store') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                notyf.success(res.message);
                setTimeout(() => window.location.href = '<?= url('/admin/content/' . $submission->id) ?>', 1500);
            } else {
                notyf.error(res.message);
                submitBtn.disabled = false;
            }
        })
        .catch(() => {
            notyf.error('خطا در ارتباط.');
            submitBtn.disabled = false;
        });
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>