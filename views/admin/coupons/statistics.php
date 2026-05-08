<?php
$title = 'آمار کوپن‌ها';
ob_start();
?>

<div class="container-fluid py-4">
    <!-- کارت‌های آماری کلی -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card">
                <div class="card-body p-3">
                    <div class="d-flex">
                        <div class="icon icon-shape bg-gradient-primary text-white rounded-circle shadow text-center">
                            <i class="fas fa-ticket-alt fa-2x"></i>
                        </div>
                        <div class="ms-3">
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">کو��ن‌های فعال</p>
                            <h5 class="font-weight-bolder mb-0"><?= e($stats['active_coupons_count']) ?></h5>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card">
                <div class="card-body p-3">
                    <div class="d-flex">
                        <div class="icon icon-shape bg-gradient-danger text-white rounded-circle shadow text-center">
                            <i class="fas fa-clock fa-2x"></i>
                        </div>
                        <div class="ms-3">
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">کوپن‌های منقضی</p>
                            <h5 class="font-weight-bolder mb-0"><?= e($stats['expired_coupons_count']) ?></h5>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card">
                <div class="card-body p-3">
                    <div class="d-flex">
                        <div class="icon icon-shape bg-gradient-success text-white rounded-circle shadow text-center">
                            <i class="fas fa-chart-line fa-2x"></i>
                        </div>
                        <div class="ms-3">
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">کل استفاده‌ها</p>
                            <h5 class="font-weight-bolder mb-0"><?= $stats['overall']->total_redemptions ?? 0 ?></h5>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card">
                <div class="card-body p-3">
                    <div class="d-flex">
                        <div class="icon icon-shape bg-gradient-warning text-white rounded-circle shadow text-center">
                            <i class="fas fa-money-bill-wave fa-2x"></i>
                        </div>
                        <div class="ms-3">
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">مجموع تخفیف داده شده</p>
                            <h5 class="font-weight-bolder mb-0"><?= number_format($stats['overall']->total_discount_given ?? 0) ?></h5>
                            <small class="text-muted">تومان</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- جزئیات بیشتر -->
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">آمار کلی</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>کاربران یکتا:</th>
                            <td><strong><?= $stats['overall']->unique_users ?? 0 ?></strong></td>
                        </tr>
                        <tr>
                            <th>کوپن‌های استفاده شده:</th>
                            <td><strong><?= $stats['overall']->used_coupons ?? 0 ?></strong></td>
                        </tr>
                        <tr>
                            <th>میانگین تخفیف:</th>
                            <td><strong><?= number_format($stats['overall']->avg_discount_per_use ?? 0) ?></strong> تومان</td>
                        </tr>
                        <tr>
                            <th>استفاده امروز:</th>
                            <td><strong><?= e($stats['today_redemptions_count']) ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">دسترسی سریع</h6>
                </div>
                <div class="card-body">
                    <a href="<?= url('admin/coupons') ?>" class="btn btn-primary w-100 mb-2">
                        <i class="fas fa-list"></i> لیست کوپن‌ها
                    </a>
                    <a href="<?= url('admin/coupons/create') ?>" class="btn btn-success w-100 mb-2">
                        <i class="fas fa-plus"></i> ایجاد کوپن جدید
                    </a>
                    <a href="<?= url('admin/coupons/redemptions') ?>" class="btn btn-info w-100">
                        <i class="fas fa-history"></i> تاریخچه مصرف
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'views/admin/layout.php';
?>