<?php $title = 'اعمال سود/ضرر هفتگی'; $layout = 'admin'; ob_start(); ?>

<div class="content-header">
    <h4><i class="material-icons">calculate</i> اعمال سود/ضرر هفتگی</h4>
    <a href="<?= url('/admin/investment') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons">arrow_back</i> بازگشت
    </a>
</div>

<div class="alert alert-warning">
    <i class="material-icons">warning</i>
    <strong>توجه:</strong> این عملیات سود/ضرر را بر تمام سرمایه‌گذاری‌های فعال اعمال می‌کند. لطفاً با دقت انجام دهید.
</div>

<div class="card">
    <div class="card-header"><h5>تنظیمات هفتگی</h5></div>
    <div class="card-body">
        <form id="applyForm">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>ترید مرجع <span class="text-danger">*</span></label>
                    <select name="trading_record_id" class="form-control" required>
                        <option value="">انتخاب ترید...</option>
                        <?php foreach ($closedTrades as $t): ?>
                        <option value="<?= e($t->id) ?>">
                            #<?= e($t->id) ?> - <?= e($t->pair) ?> - <?= e($t->profit_loss_percent) ?>% -
                            <?= e(to_jalali($t->close_time ?? '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>درصد سود/ضرر <span class="text-danger">*</span></label>
                    <input type="number" name="profit_loss_percent" class="form-control" dir="ltr" step="0.01" required
                           placeholder="مثال: 3.5 یا -2.1">
                    <small class="text-muted">عدد مثبت = سود | عدد منفی = ضرر</small>
                </div>
                <div class="form-group col-md-4">
                    <label>دوره <span class="text-danger">*</span></label>
                    <input type="text" name="period" class="form-control" dir="ltr" placeholder="1404-W01" required>
                    <small class="text-muted">فرمت: سال-Wشماره_هفته</small>
                </div>
            </div>

            <div class="alert alert-info">
                <strong>نکته:</strong> کارمزد سایت: <?= e($settings['site_fee_percent']) ?>% | مالیات: <?= e($settings['tax_percent']) ?>%
                <br>کارمزد و مالیات فقط از سود کسر می‌شود. در ضرر، مبلغ کامل از سرمایه کاربر کم می‌شود.
            </div>

            <button type="submit" class="btn btn-danger" id="submitBtn">
                <i class="material-icons">play_arrow</i> اعمال بر تمام سرمایه‌گذاری‌ها
            </button>
        </form>
    </div>
</div>

<script>
document.getElementById('applyForm').addEventListener('submit', function(e) {
    e.preventDefault();

    Swal.fire({
        title: 'تأیید نهایی',
        text: 'آیا مطمئنید؟ این عملیات بر تمام سرمایه‌گذاری‌های فعال اعمال خواهد شد.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'بله، اعمال کن',
        cancelButtonText: 'انصراف',
        confirmButtonColor: '#f44336'
    }).then(result => {
        if (result.isConfirmed) {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;

            const fd = new FormData(document.getElementById('applyForm'));
            const data = {};
            fd.forEach((v, k) => data[k] = v);

            fetch('<?= url('/admin/investment/apply-profit') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
                body: JSON.stringify(data)
            }).then(r => r.json()).then(res => {
                if (res.success) {
                    Swal.fire('موفق!', res.message, 'success');
                } else {
                    notyf.error(res.message);
                }
                btn.disabled = false;
            }).catch(() => { notyf.error('خطا'); btn.disabled = false; });
        }
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>