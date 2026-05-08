<?php
$title = 'تاریخچه مصرف کوپن‌ها';
ob_start();
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">تاریخچه مصرف کوپن‌های تخفیف</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="redemptionsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>کد کوپن</th>
                                    <th>کاربر</th>
                                    <th>مبلغ اصلی</th>
                                    <th>تخفیف</th>
                                    <th>مبلغ نهایی</th>
                                    <th>ارز</th>
                                    <th>نوع</th>
                                    <th>IP</th>
                                    <th>تاریخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($redemptions as $redemption): ?>
                                    <tr>
                                        <td><?= e($redemption->id) ?></td>
                                        <td>
                                            <span class="badge bg-gradient-info"><?= e($redemption->code ?? 'N/A') ?></span>
                                        </td>
                                        <td><?= e($redemption->username ?? 'ناشناس') ?></td>
                                        <td><?= number_format($redemption->original_amount) ?></td>
                                        <td class="text-danger">-<?= number_format($redemption->discount_amount) ?></td>
                                        <td><strong><?= number_format($redemption->final_amount) ?></strong></td>
                                        <td>
                                            <span class="badge bg-gradient-<?= $redemption->currency === 'usdt' ? 'success' : 'primary' ?>">
                                                <?= e(strtoupper($redemption->currency)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $typeLabels = [
                                                'task' => 'تسک',
                                                'investment' => 'سرمایه‌گذاری',
                                                'vip' => 'VIP',
                                                'story_order' => 'استوری'
                                            ];
                                            ?>
                                            <span class="badge bg-gradient-secondary">
                                                <?= e($typeLabels[$redemption->entity_type] ?? $redemption->entity_type) ?>
                                            </span>
                                        </td>
                                        <td><small><?= e(e($redemption->ip_address)) ?></small></td>
                                        <td><?= jdate('Y/m/d H:i', strtotime($redemption->created_at)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
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
    $('#redemptionsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/fa.json'
        },
        order: [[0, 'desc']]
    });
});
</script>

<?php
$content = ob_get_clean();
include 'views/admin/layout.php';
?>