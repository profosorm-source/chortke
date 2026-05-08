<?php
$title = 'تاریخچه استفاده از کوپن‌ها';
$layout = 'user';
ob_start();
?>

<div class="container py-5">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">تاریخچه استفاده از کوپن‌های تخفیف</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($history)): ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle fa-2x mb-3"></i>
                            <p class="mb-0">شما هنوز از هیچ کوپن تخفیفی استفاده نکرده‌اید</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>کد کوپن</th>
                                        <th>نوع تخفیف</th>
                                        <th>مبلغ اصلی</th>
                                        <th>تخفیف</th>
                                        <th>مبلغ نهایی</th>
                                        <th>استفاده در</th>
                                        <th>تاریخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $item): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-gradient-info"><?= e(e($item->code)) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($item->type === 'percent'): ?>
                                                    <span class="badge bg-gradient-success"><?= e($item->value) ?>%</span>
                                                <?php else: ?>
                                                    <span class="badge bg-gradient-warning">ثابت</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= number_format($item->original_amount) ?> <?= $item->currency === 'usdt' ? 'USDT' : 'تومان' ?></td>
                                            <td class="text-danger">-<?= number_format($item->discount_amount) ?></td>
                                            <td><strong><?= number_format($item->final_amount) ?></strong></td>
                                            <td>
                                                <?php
                                                $typeLabels = [
                                                    'task' => 'سفارش تسک',
                                                    'investment' => 'سرمایه‌گذاری',
                                                    'vip' => 'خرید VIP',
                                                    'story_order' => 'سفارش استوری'
                                                ];
                                                ?>
                                                <?= e($typeLabels[$item->entity_type] ?? $item->entity_type) ?>
                                            </td>
                                            <td><?= jdate('Y/m/d H:i', strtotime($item->created_at)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>