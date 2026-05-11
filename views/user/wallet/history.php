<?php
$pageTitle = $pageTitle ?? 'تاریخچه تراکنش‌ها';
$transactions = $transactions ?? [];
$currentPage = $currentPage ?? 1;
$totalPages = $totalPages ?? 1;
$type = $type ?? '';
$currency = $currency ?? '';
?>
<?php
$title = $title ?? 'تاریخچه تراکنش‌ها';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-wallet-history.css') ?>">


    <!-- Header -->
    <div class="content-header">
        <h1>تاریخچه تراکنش‌ها</h1>
        <a href="<?= url('/wallet') ?>" class="btn btn-outline">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <!-- فیلترها -->
    <div class="filters-card">
        <form method="GET" action="<?= url('/wallet/history') ?>" class="filters-form">
            <div class="filter-group">
                <label>نوع تراکنش:</label>
                <select name="type" class="form-control">
                    <option value="">همه</option>
                    <option value="deposit" <?= $type === 'deposit' ? 'selected' : '' ?>>واریز</option>
                    <option value="withdraw" <?= $type === 'withdraw' ? 'selected' : '' ?>>برداشت</option>
                    <option value="commission" <?= $type === 'commission' ? 'selected' : '' ?>>کمیسیون</option>
                    <option value="task_reward" <?= $type === 'task_reward' ? 'selected' : '' ?>>پاداش تسک</option>
                </select>
            </div>

            <div class="filter-group">
                <label>نوع ارز:</label>
                <select name="currency" class="form-control">
                    <option value="">همه</option>
                    <option value="irt" <?= $currency === 'irt' ? 'selected' : '' ?>>تومان</option>
                    <option value="usdt" <?= $currency === 'usdt' ? 'selected' : '' ?>>USDT</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="material-icons">search</i>
                فیلتر
            </button>

            <?php if ($type || $currency): ?>
            <a href="<?= url('/wallet/history') ?>" class="btn btn-outline">
                <i class="material-icons">clear</i>
                حذف فیلتر
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- جدول تراکنش‌ها -->
    <div class="table-card">
        <?php if (empty($transactions)): ?>
        <div class="empty-state">
            <i class="material-icons">receipt_long</i>
            <h3>تراکنشی یافت نشد</h3>
            <p>هنوز هیچ تراکنشی ثبت نشده است</p>
            <a href="<?= url('/wallet/deposit') ?>" class="btn btn-primary">
                افزایش موجودی
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>شناسه تراکنش</th>
                        <th>نوع</th>
                        <th>مبلغ</th>
                        <th>ارز</th>
                        <th>وضعیت</th>
                        <th>درگاه</th>
                        <th>تاریخ</th>
                        <th>جزئیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td>
                            <code class="tx-id"><?= e(substr($tx->transaction_id, 0, 8)) ?>...</code>
                        </td>
                        <td>
                            <span class="tx-type <?= e($tx->type) ?>">
                                <?php
                                $typeLabels = [
                                    'deposit' => 'واریز',
                                    'withdraw' => 'برداشت',
                                    'transfer' => 'انتقال',
                                    'commission' => 'کمیسیون',
                                    'investment' => 'سرمایه‌گذاری',
                                    'task_reward' => 'پاداش تسک',
                                    'penalty' => 'جریمه',
                                    'refund' => 'بازگشت وجه',
                                ];
                                echo e($typeLabels[$tx->type] ?? $tx->type);
                                ?>
                            </span>
                        </td>
                        <td>
                            <span class="tx-amount <?= $tx->type === 'withdraw' || $tx->type === 'penalty' ? 'negative' : 'positive' ?>">
                                <?php if ($tx->type === 'withdraw' || $tx->type === 'penalty'): ?>
                                -
                                <?php else: ?>
                                +
                                <?php endif; ?>
                                <?= $tx->currency === 'usdt' ? number_format($tx->amount, 4) : number_format($tx->amount) ?>
                            </span>
                        </td>
                        <td>
                            <span class="currency-badge <?= e($tx->currency) ?>">
                                <?= $tx->currency === 'usdt' ? 'USDT' : 'تومان' ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $statusLabels = [
                                'pending' => 'در انتظار',
                                'processing' => 'در حال پردازش',
                                'completed' => 'تکمیل شده',
                                'failed' => 'ناموفق',
                                'cancelled' => 'لغو شده',
                                'refunded' => 'بازگشت داده شده',
                            ];
                            ?>
                            <span class="status-badge <?= e($tx->status) ?>">
                                <?= e($statusLabels[$tx->status] ?? $tx->status) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($tx->gateway): ?>
                            <span class="gateway-name"><?= e($tx->gateway) ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="tx-date"><?= to_jalali($tx->created_at) ?></span>
                        </td>
                        <td>
                            <button class="btn-icon" onclick="showTransactionDetails(<?= e($tx->id) ?>)" title="جزئیات">
                                <i class="material-icons">visibility</i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- صفحه‌بندی -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($currentPage > 1): ?>
            <a href="?page=<?= $currentPage - 1 ?><?= $type ? '&type=' . $type : '' ?><?= $currency ? '&currency=' . $currency : '' ?>" class="page-link">
                <i class="material-icons">chevron_right</i>
            </a>
            <?php endif; ?>

            <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
            <a href="?page=<?= e($i) ?><?= $type ? '&type=' . $type : '' ?><?= $currency ? '&currency=' . $currency : '' ?>" 
               class="page-link <?= $i === $currentPage ? 'active' : '' ?>">
                <?= to_jalali($i, '', true) ?>
            </a>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
            <a href="?page=<?= $currentPage + 1 ?><?= $type ? '&type=' . $type : '' ?><?= $currency ? '&currency=' . $currency : '' ?>" class="page-link">
                <i class="material-icons">chevron_left</i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function showTransactionDetails(id) {
    // TODO: نمایش جزئیات تراکنش در مودال
    alert('جزئیات تراکنش ' + id);
}
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>