<?php
$title = 'ایجاد کوپن جدید';
ob_start();
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">ایجاد کوپن تخفیف جدید</h5>
                </div>
                <div class="card-body">
                    <form id="createCouponForm">
                        <div class="row">
                            <!-- کد کوپن -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">کد کوپن *</label>
                                <input type="text" 
                                       name="code" 
                                       class="form-control" 
                                       placeholder="مثال: WELCOME10"
                                       required>
                                <small class="text-muted">فقط حروف انگلیسی و اعداد</small>
                            </div>

                            <!-- نوع تخفیف -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">نوع تخفیف *</label>
                                <select name="type" class="form-select" id="discountType" required>
                                    <option value="percent">درصدی</option>
                                    <option value="fixed">مبلغ ثابت</option>
                                </select>
                            </div>

                            <!-- مقدار تخفیف -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">مقدار تخفیف *</label>
                                <input type="number" 
                                       name="value" 
                                       class="form-control" 
                                       step="0.01"
                                       min="0"
                                       placeholder="10"
                                       required>
                                <small class="text-muted" id="valueHint">درصد تخفیف (0-100)</small>
                            </div>

                            <!-- کاربرد کوپن -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">کاربرد کوپن *</label>
                                <select name="applicable_to" class="form-select" required>
                                    <option value="all">همه موارد</option>
                                    <option value="task">سفارش تسک</option>
                                    <option value="investment">سرمایه‌گذاری</option>
                                    <option value="vip">خرید VIP</option>
                                    <option value="story_order">سفارش استوری</option>
                                </select>
                            </div>

                            <!-- حداقل خرید -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">حداقل مبلغ خرید (تومان)</label>
                                <input type="number" 
                                       name="min_purchase" 
                                       class="form-control" 
                                       placeholder="اختیاری">
                            </div>

                            <!-- حداکثر تخفیف -->
                            <div class="col-md-6 mb-3" id="maxDiscountField">
                                <label class="form-label">حداکثر تخفیف (تومان)</label>
                                <input type="number" 
                                       name="max_discount" 
                                       class="form-control" 
                                       placeholder="برای تخفیف درصدی">
                            </div>

                            <!-- محدودیت استفاده -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">محدودیت تعداد استفاده</label>
                                <input type="number" 
                                       name="usage_limit" 
                                       class="form-control" 
                                       value="0"
                                       placeholder="0 = نامحدود">
                            </div>

                            <!-- تاریخ شروع -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاریخ شروع</label>
                                <input type="datetime-local" 
                                       name="start_date" 
                                       class="form-control">
                            </div>

                            <!-- تاریخ پایان -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاریخ پایان</label>
                                <input type="datetime-local" 
                                       name="end_date" 
                                       class="form-control">
                            </div>

                            <!-- وضعیت فعال -->
                            <div class="col-12 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="active" 
                                           id="activeCheck"
                                           checked>
                                    <label class="form-check-label" for="activeCheck">
                                        فعال
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="<?= url('admin/coupons') ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-right"></i> بازگشت
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> ذخیره کوپن
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // تغییر نوع تخفیف
    $('#discountType').on('change', function() {
        if ($(this).val() === 'percent') {
            $('#valueHint').text('درصد تخفیف (0-100)');
            $('#maxDiscountField').show();
        } else {
            $('#valueHint').text('مبلغ تخفیف به تومان');
            $('#maxDiscountField').hide();
        }
    });

    // ارسال فرم
    $('#createCouponForm').on('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const data = Object.fromEntries(formData);

        $.ajax({
            url: '<?= url('admin/coupons/store') ?>',
            method: 'POST',
            data: JSON.stringify(data),
            contentType: 'application/json',
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.message);
                    setTimeout(() => {
                        window.location.href = response.redirect;
                    }, 1500);
                } else {
                    if (response.errors) {
                        let errorMsg = '';
                        for (let field in response.errors) {
                            errorMsg += response.errors[field].join('<br>') + '<br>';
                        }
                        showAlert('error', errorMsg);
                    } else {
                        showAlert('error', response.message);
                    }
                }
            },
            error: function() {
                showAlert('error', 'خطا در ایجاد کوپن');
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include 'views/admin/layout.php';
?>