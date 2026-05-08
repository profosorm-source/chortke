<?php
$title = $title ?? 'تاریخچه';
$layout = 'user';
ob_start();
?>

<div class="page-header">
    <h4><i class="material-icons">history</i> تاریخچه تسک‌ها</h4>
    <a href="<?= url('/seo') ?>" class="btn btn-primary">بازگشت</a>
</div>

<?php if (empty($executions)): ?>
    <div class="empty-state">
        <i class="material-icons">inbox</i>
        <h5>تاریخچه‌ای وجود ندارد</h5>
    </div>
<?php else: ?>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>عنوان</th>
                    <th>امتیاز</th>
                    <th>پاداش</th>
                    <th>وضعیت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($executions as $ex): ?>
                <tr>
                    <td><?= jdate('Y/m/d H:i', strtotime($ex->created_at)) ?></td>
                    <td><?= e($ex->ad_title ?? 'حذف شده') ?></td>
                    <td><?= round($ex->final_score, 1) ?>/100</td>
                    <td><?= number_format($ex->payout_amount) ?></td>
                    <td>
                        <?php
                        $badges = [
                            'completed' => '<span class="badge badge-success">تکمیل شده</span>',
                            'fraud' => '<span class="badge badge-danger">تقلب</span>',
                            'rejected' => '<span class="badge badge-warning">رد شده</span>',
                        ];
                        echo $badges[$ex->status] ?? $ex->status;
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
