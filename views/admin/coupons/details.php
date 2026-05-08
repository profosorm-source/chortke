<?php
$title = 'جزئیات کوپن';
ob_start();
?>

<div class="container-fluid py-4">
    <div class="row">
        <!-- اطلاعات کوپن -->
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">اطلاعات کوپن</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>کد:</th>
                            <td><span class="badge bg-gradient-info"><?= e(e($coupon->code)) ?></span></td>
                        </tr>
                        <tr>
                            <th>نوع:</th>
                            <td>
                                <?php if ($coupon->type === 'percent'): ?>
                                    <span class="badge bg-gradient-success">درصدی - <?= e($coupon->value) ?>%</span>
                                <?php else: ?>
                                    <span class="badge bg-gradient-warning">ثابت - <?= number_format($coupon->value) ?> تومان</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>کاربرد:</th>
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
                                <?= e($labels[$coupon->applicable_to] ?? $coupon->applicable_to) ?>
                            </td>
                        </tr>
                        <?php if ($coupon->min_purchase): ?>
                        <tr>
                            <th>حداقل خرید:</th>
                            <td><?= number_format($coupon->min_purchase) ?> تومان</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($coupon->max_discount): ?>
                        <tr>
                            <th>حداکثر تخفیف:</th>
                            <td><?= number_format($coupon->max_discount) ?> تومان</td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>محدودیت استفاده:</th>
                            <td><?= $coupon->usage_limit > 0 ? $coupon->usage_limit : 'نامحدود' ?></td>
                        </tr>
                        <tr>
                            <th>تعداد استفاده:</th>
                            <td><strong><?= e($coupon->usage_count) ?></strong></td>
                        </tr>
                        <tr>
                            <th>وضعیت:</th>
                            <td>
                                <?php if ($coupon->active): ?>
                                    <span class="badge bg-gradient-success">فعال</span>
                                <?php else: ?>
                                    <span class="badge bg-gradient-danger">غیرفعال</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($coupon->start_date): ?>
                        <tr>
                            <th>شروع:</th>
                            <td><?= jdate('Y/m/d H:i', strtotime($coupon->start_date)) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($coupon->end_date): ?>
                        <tr>
                            <th>پایان:</th>
                            <td><?= jdate('Y/m/d H:i', strtotime($coupon->end_date)) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>ایجاد شده:</th>
                            <td><?= jdate('Y/m/d H:i', strtotime($coupon->created_at)) ?></td>
                        </tr>
                    </table>

                    <div class="mt-3">
                        <a href="<?= url('admin/coupons/edit?id=' . $coupon->id) ?>" class="btn btn-warning btn-sm w-100 mb-2">
                            <i class="fas fa-edit"></i> ویرایش
                        </a>
                        <a href="<?= url('admin/coupons') ?>" class="btn btn-secondary btn-sm w-100">
                            <i class="fas fa-arrow-right"></i> بازگشت
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- آمار -->
        <div class="col-md-8">
            <div class="row">
                <!-- کارت‌های آماری -->
                <div class="col-md-3 mb-4">
                    <div class="card">
                        <div class="card-body p-3 text-center">
                            <h6 class="text-muted mb-0">کل استفاده</h6>
                            <h3 class="mb-0"><?= $stats->total_uses ?? 0 ?></h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-4">
                    <div class="card">
                        <div class="card-body p-3 text-center">
                            <h6 class="text-muted mb-0">مجموع تخفیف</h6>
                            <h3 class="mb-0"><?= number_format($stats->total_discount ?? 0) ?></h3>
                            <small class="text-muted">تومان</small>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-4">
                    <div class="card">
                        <div class="card-body p-3 text-center">
                            <h6 class="text-muted mb-0">میانگین تخفیف</h6>
                            <h3 class="mb-0"><?= number_format($stats->avg_discount ?? 0) ?></h3>
                            <small class="text-muted">تومان</small>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-4">
                    <div class="card">
                        <div class="card-body p-3 text-center">
                            <h6 class="text-muted mb-0">بیشترین تخفیف</h6>
                            <h3 class="mb-0"><?= number_format($stats->max_discount ?? 0) ?></h3>
                            <small class="text-muted">تومان</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- آخرین استفاده‌ها -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">آخرین استفاده‌ها</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>کاربر</th>
                                    <th>مبلغ اصلی</th>
                                    <th>تخفیف</th>
                                    <th>مبلغ نهایی</th>
                                    <th>تاریخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_uses)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">استفاده‌ای ثبت نشده</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_uses as $use): ?>
                                        <tr>
                                            <td><?= e($use->username ?? 'ناشناس') ?></td>
                                            <td><?= number_format($use->original_amount) ?></td>
                                            <td class="text-danger">-<?= number_format($use->discount_amount) ?></td>
                                            <td><strong><?= number_format($use->final_amount) ?></strong></td>
                                            <td><?= jdate('Y/m/d H:i', strtotime($use->created_at)) ?></td>
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

<?php
$content = ob_get_clean();
include 'views/admin/layout.php';
?>