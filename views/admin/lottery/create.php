<?php $title = 'ایجاد دوره قرعه‌کشی'; $layout = 'admin'; ob_start(); ?>

<div class="content-header">
    <h4><i class="material-icons">add_circle</i> ایجاد دوره جدید</h4>
    <a href="<?= url('/admin/lottery') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons">arrow_back</i> بازگشت</a>
</div>

<div class="card">
    <div class="card-body">
        <form id="roundForm">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>عنوان دوره <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required placeholder="مثال: قرعه‌کشی هفته اول تیر">
                </div>
                <div class="form-group col-md-3">
                    <label>نوع</label>
                    <select name="type" class="form-control">
                        <option value="weekly">هفتگی</option>
                        <option value="monthly">ماهانه</option>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label>مدت (روز)</label>
                    <input type="number" name="duration_days" class="form-control" value="7" min="1" max="31">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label>هزینه ورود</label>
                    <input type="number" name="entry_fee" class="form-control" value="0" min="0" step="0.01">
                </div>
                <div class="form-group col-md-3">
                    <label>ارز</label>
                    <select name="currency" class="form-control">
                        <option value="irt">تومان</option>
                        <option value="usdt">تتر</option>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label>مبلغ جایزه <span class="text-danger">*</span></label>
                    <input type="number" name="prize_amount" class="form-control" min="0" step="0.01" required>
                </div>
                <div class="form-group col-md-3">
                    <label>توضیح جایزه</label>
                    <input type="text" name="prize_description" class="form-control" placeholder="مثال: 500 هزار تومان نقدی">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>تاریخ شروع <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="start_date" class="form-control" required>
                </div>
                <div class="form-group col-md-6">
                    <label>تاریخ پایان <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="end_date" class="form-control" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="material-icons">save</i> ایجاد دوره
            </button>
        </form>
    </div>
</div>

<script>
document.getElementById('roundForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    const fd = new FormData(this);
    const data = {};
    fd.forEach((v, k) => { if (v) data[k] = v; });

    fetch('<?= url('/admin/lottery/store') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
        body: JSON.stringify(data)
    }).then(r => r.json()).then(res => {
        if (res.success) {
            notyf.success(res.message);
            setTimeout(() => window.location.href = '<?= url('/admin/lottery') ?>', 1500);
        } else {
            notyf.error(res.message || 'خطا');
            btn.disabled = false;
        }
    }).catch(() => { notyf.error('خطا'); btn.disabled = false; });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>