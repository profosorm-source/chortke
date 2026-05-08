<?php
$title = 'مدیریت کوپن‌های تخفیف';
ob_start();
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">لیست کوپن‌های تخفیف</h5>
                    <a href="<?= url('admin/coupons/create') ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> افزودن کوپن جدید
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-items-center mb-0" id="couponsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>کد</th>
                                    <th>نوع</th>
                                    <th>مقدار</th>
                                    <th>کاربرد</th>
                                    <th>محدودیت استفاده</th>
                                    <th>تعداد استفاده</th>
                                    <th>وضعیت</th>
                                    <th>تاریخ انقضا</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($coupons)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center">کوپنی یافت نشد</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($coupons as $coupon): ?>
                                        <tr>
                                            <td><?= e($coupon->id) ?></td>
                                            <td>
                                                <span class="badge bg-gradient-info"><?= e(e($coupon->code)) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($coupon->type === 'percent'): ?>
                                                    <span class="badge bg-gradient-success">درصدی</span>
                                                <?php else: ?>
                                                    <span class="badge bg-gradient-warning">مبلغ ثابت</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($coupon->type === 'percent'): ?>
                                                    <?= e($coupon->value) ?>%
                                                <?php else: ?>
                                                    <?= number_format($coupon->value) ?> تومان
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $labels = [
                                                    'all' => 'همه',
                                                    'task' => 'سفارش تسک',
                                                    'investment' => 'سرمایه‌گذاری',
                                                    'vip' => 'VIP',
                                                    'story_order' => 'سفارش استوری'
                                                ];
                                                ?>
                                                <span class="badge bg-gradient-primary"><?= e($labels[$coupon->applicable_to] ?? $coupon->applicable_to) ?></span>
                                            </td>
                                            <td><?= $coupon->usage_limit > 0 ? $coupon->usage_limit : 'نامحدود' ?></td>
                                            <td>
                                                <span class="badge bg-gradient-secondary"><?= e($coupon->usage_count) ?></span>
                                            </td>
                                            <td>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input toggle-active" 
                                                           type="checkbox" 
                                                           data-id="<?= e($coupon->id) ?>"
                                                           <?= $coupon->active ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($coupon->end_date): ?>
                                                    <?= jdate('Y/m/d H:i', strtotime($coupon->end_date)) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">نامحدود</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="<?= url('admin/coupons/details?id=' . $coupon->id) ?>" 
                                                   class="btn btn-info btn-sm" 
                                                   title="جزئیات">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?= url('admin/coupons/edit?id=' . $coupon->id) ?>" 
                                                   class="btn btn-warning btn-sm" 
                                                   title="ویرایش">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button class="btn btn-danger btn-sm delete-coupon" 
                                                        data-id="<?= e($coupon->id) ?>" 
                                                        title="حذف">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // DataTable
    $('#couponsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/fa.json'
        }
    });

    // تغییر وضعیت فعال/غیرفعال
    $('.toggle-active').on('change', function() {
        const couponId = $(this).data('id');
        const checkbox = $(this);

        $.ajax({
            url: '<?= url('admin/coupons/toggle-active') ?>',
            method: 'POST',
            data: JSON.stringify({ id: couponId }),
            contentType: 'application/json',
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.message);
                } else {
                    checkbox.prop('checked', !checkbox.is(':checked'));
                    showAlert('error', response.message);
                }
            },
            error: function() {
                checkbox.prop('checked', !checkbox.is(':checked'));
                showAlert('error', 'خطا در تغییر وضعیت');
            }
        });
    });

    // حذف کوپن
    $('.delete-coupon').on('click', function() {
        const couponId = $(this).data('id');
        const row = $(this).closest('tr');

        if (confirm('آیا از حذف این کوپن اطمینان دارید؟')) {
            $.ajax({
                url: '<?= url('admin/coupons/delete') ?>',
                method: 'POST',
                data: JSON.stringify({ id: couponId }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        row.fadeOut(300, function() { $(this).remove(); });
                        showAlert('success', response.message);
                    } else {
                        showAlert('error', response.message);
                    }
                },
                error: function() {
                    showAlert('error', 'خطا در حذف کوپن');
                }
            });
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include 'views/admin/layout.php';
?>