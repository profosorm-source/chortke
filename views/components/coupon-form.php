<div class="coupon-section mb-4">
    <div class="card">
        <div class="card-body">
            <h6 class="card-title mb-3">
                <i class="fas fa-tag text-primary"></i> کد تخفیف دارید؟
            </h6>
            
            <div class="row align-items-end" id="couponFormContainer">
                <div class="col-md-8">
                    <input type="text" 
                           class="form-control" 
                           id="couponCode" 
                           placeholder="کد تخفیف را وارد کنید"
                           maxlength="50">
                </div>
                <div class="col-md-4">
                    <button type="button" 
                            class="btn btn-primary w-100" 
                            id="applyCouponBtn">
                        <i class="fas fa-check"></i> اعمال کوپن
                    </button>
                </div>
            </div>

            <div id="couponResult" class="mt-3" style="display: none;"></div>
        </div>
    </div>
</div>

<script>
let appliedCoupon = null;

$('#applyCouponBtn').on('click', function() {
    const code = $('#couponCode').val().trim();
    const amount = parseFloat($('#totalAmount').val() || 0);
    const currency = '<?= $currency ?? 'irt' ?>';
    const applicableTo = '<?= $applicable_to ?? 'all' ?>';

    if (!code) {
        showAlert('error', 'لطفا کد تخفیف را وارد کنید');
        return;
    }

    if (amount <= 0) {
        showAlert('error', 'مبلغ نامعتبر است');
        return;
    }

    const btn = $(this);
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> در حال بررسی...');

    $.ajax({
        url: '<?= url('coupons/validate') ?>',
        method: 'POST',
        data: JSON.stringify({
            code: code,
            amount: amount,
            currency: currency,
            applicable_to: applicableTo
        }),
        contentType: 'application/json',
        success: function(response) {
            if (response.success) {
                appliedCoupon = response.data;
                
                $('#couponResult').html(`
                    <div class="alert alert-success">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-check-circle"></i>
                                <strong>${response.message}</strong>
                                <br>
                                <small>
                                    مبلغ اصلی: ${numberFormat(appliedCoupon.original_amount)} - 
                                    تخفیف: ${numberFormat(appliedCoupon.discount_amount)} = 
                                    <strong>مبلغ نهایی: ${numberFormat(appliedCoupon.final_amount)}</strong>
                                </small>
                            </div>
                            <button type="button" class="btn btn-sm btn-danger" id="removeCoupon">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                `).fadeIn();

                // بروزرسانی مبلغ نهایی در فرم
                $('#finalAmount').val(appliedCoupon.final_amount);
                $('#displayFinalAmount').text(numberFormat(appliedCoupon.final_amount));
                
                $('#couponCode').prop('disabled', true);
                btn.hide();
            } else {
                showAlert('error', response.message);
            }
        },
        error: function() {
            showAlert('error', 'خطا در اعتبارسنجی کوپن');
        },
        complete: function() {
            btn.prop('disabled', false).html('<i class="fas fa-check"></i> اعمال کوپن');
        }
    });
});

$(document).on('click', '#removeCoupon', function() {
    appliedCoupon = null;
    $('#couponResult').fadeOut();
    $('#couponCode').val('').prop('disabled', false);
    $('#applyCouponBtn').show();
    
    // بازگشت به مبلغ اصلی
    const originalAmount = parseFloat($('#totalAmount').val());
    $('#finalAmount').val(originalAmount);
    $('#displayFinalAmount').text(numberFormat(originalAmount));
});

function numberFormat(num) {
    return new Intl.NumberFormat('fa-IR').format(num);
}
</script>