<?php
$title = 'جزئیات سرمایه‌گذاری';
$layout = 'admin';
ob_start();
$investment = $investment ?? null;
$profits = $profits ?? [];
$totalStats = $totalStats ?? null;
$withdrawals = $withdrawals ?? [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">سرمایه‌گذاری #<?= (int)($investment->id ?? 0) ?></h4>
    <a href="<?= url('/admin/investment') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons align-middle">arrow_forward</i> بازگشت
    </a>
</div>

<!-- آمار کلی -->
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card text-center p-3">
        <div class="text-muted small">سرمایه اولیه</div>
        <div class="fs-5 fw-bold text-primary"><?= number_format((float)($investment->amount ?? 0), 2) ?> USDT</div>
    </div></div>
    <div class="col-md-3"><div class="card text-center p-3">
        <div class="text-muted small">موجودی فعلی</div>
        <div class="fs-5 fw-bold"><?= number_format((float)($investment->current_balance ?? 0), 2) ?> USDT</div>
    </div></div>
    <div class="col-md-3"><div class="card text-center p-3">
        <div class="text-muted small">مجموع سود</div>
        <div class="fs-5 fw-bold text-success"><?= number_format((float)($totalStats->total_profit ?? 0), 2) ?></div>
    </div></div>
    <div class="col-md-3"><div class="card text-center p-3">
        <div class="text-muted small">مجموع ضرر</div>
        <div class="fs-5 fw-bold text-danger"><?= number_format((float)($totalStats->total_loss ?? 0), 2) ?></div>
    </div></div>
</div>

<!-- تاریخچه سود/زیان -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">تاریخچه سود و زیان</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>تاریخ</th><th>مقدار</th><th>درصد</th><th>موجودی بعد</th></tr>
            </thead>
            <tbody>
                <?php foreach ($profits as $p): ?>
                <?php $pos = (float)($p->amount ?? 0) >= 0; ?>
                <tr>
                    <td><?= to_jalali($p->profit_date ?? $p->created_at) ?></td>
                    <td class="<?= $pos ? 'text-success' : 'text-danger' ?> font-monospace">
                        <?= $pos ? '+' : '' ?><?= number_format((float)($p->amount ?? 0), 4) ?>
                    </td>
                    <td class="<?= $pos ? 'text-success' : 'text-danger' ?>">
                        <?= !empty($p->percent) ? ($pos ? '+' : '') . number_format((float)$p->percent, 2) . '%' : '—' ?>
                    </td>
                    <td><?= !empty($p->balance_after) ? number_format((float)$p->balance_after, 4) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($profits)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">هیچ رکوردی ثبت نشده</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- برداشت‌ها -->
<?php if (!empty($withdrawals)): ?>
<div class="card">
    <div class="card-header"><h6 class="mb-0">درخواست‌های برداشت</h6></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>تاریخ</th><th>مقدار</th><th>وضعیت</th></tr>
            </thead>
            <tbody>
                <?php foreach ($withdrawals as $w): ?>
                <tr>
                    <td><?= to_jalali($w->created_at) ?></td>
                    <td><?= number_format((float)($w->amount ?? 0), 4) ?></td>
                    <td>
                        <?php $stMap=['pending'=>'warning','approved'=>'success','rejected'=>'danger']; ?>
                        <span class="badge bg-<?= $stMap[$w->status??'']??'secondary' ?>"><?= e($w->status??'—') ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
