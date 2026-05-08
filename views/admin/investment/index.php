<?php $title = 'مدیریت سرمایه‌گذاری'; $layout = 'admin'; ob_start(); ?>

<div class="content-header">
    <h4><i class="material-icons">trending_up</i> مدیریت سرمایه‌گذاری</h4>
    <div>
        <a href="<?= url('/admin/investment/trades') ?>" class="btn btn-sm btn-outline-primary">
            <i class="material-icons">candlestick_chart</i> تریدها
        </a>
        <a href="<?= url('/admin/investment/apply-profit') ?>" class="btn btn-sm btn-primary">
            <i class="material-icons">calculate</i> اعمال سود/ضرر
        </a>
        <a href="<?= url('/admin/investment/withdrawals') ?>" class="btn btn-sm btn-outline-warning">
            <i class="material-icons">savings</i> برداشت‌ها
        </a>
    </div>
</div>

<!-- آمار -->
<div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-icon"><i class="material-icons">people</i></div>
        <div class="stat-info">
            <span class="stat-label">فعال</span>
            <span class="stat-value"><?= e($stats->active_count ?? 0) ?></span>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon"><i class="material-icons">attach_money</i></div>
        <div class="stat-info">
            <span class="stat-label">کل سرمایه (USDT)</span>
            <span class="stat-value"><?= number_format($stats->total_invested ?? 0, 2) ?></span>
        </div>
    </div>
    <div class="stat-card stat-orange">
        <div class="stat-icon"><i class="material-icons">account_balance_wallet</i></div>
        <div class="stat-info">
            <span class="stat-label">موجودی کل</span>
            <span class="stat-value"><?= number_format($stats->total_balance ?? 0, 2) ?></span>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon"><i class="material-icons">show_chart</i></div>
        <div class="stat-info">
            <span class="stat-label">تریدها</span>
            <span class="stat-value"><?= e($tradeStats->total ?? 0) ?> (باز: <?= e($tradeStats->open_count ?? 0) ?>)</span>
        </div>
    </div>
</div>

<!-- فیلتر و لیست -->
<div class="card">
    <div class="card-header">
        <h5>لیست سرمایه‌گذاری‌ها (<?= number_format($total) ?>)</h5>
        <form method="GET" style="display:flex; gap:8px;">
            <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                <option value="">همه</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="frozen" <?= ($filters['status'] ?? '') === 'frozen' ? 'selected' : '' ?>>فریز</option>
                <option value="closed" <?= ($filters['status'] ?? '') === 'closed' ? 'selected' : '' ?>>بسته</option>
                <option value="suspended" <?= ($filters['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>تعلیق</option>
            </select>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="جستجو..."
                   value="<?= e($filters['search'] ?? '') ?>">
            <button class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($investments)): ?>
            <p class="text-center text-muted">سرمایه‌گذاری‌ای یافت نشد.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>کاربر</th>
                        <th>سرمایه</th>
                        <th>موجودی فعلی</th>
                        <th>سود کل</th>
                        <th>ضرر کل</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($investments as $inv): ?>
                    <tr>
                        <td><?= e($inv->id) ?></td>
                        <td><?= e($inv->user_name ?? '') ?></td>
                        <td><?= number_format($inv->amount, 2) ?></td>
                        <td class="<?= $inv->current_balance >= $inv->amount ? 'text-success' : 'text-danger' ?>">
                            <?= number_format($inv->current_balance, 2) ?>
                        </td>
                        <td class="text-success"><?= number_format($inv->total_profit, 2) ?></td>
                        <td class="text-danger"><?= number_format($inv->total_loss, 2) ?></td>
                        <td>
                            <?php
                            $isl = [
                                'active' => ['فعال', 'badge-success'],
                                'frozen' => ['فریز', 'badge-info'],
                                'closed' => ['بسته', 'badge-secondary'],
                                'suspended' => ['تعلیق', 'badge-danger'],
                            ][$inv->status] ?? ['؟', 'badge-secondary'];
                            ?>
                            <span class="badge <?= e($isl[1]) ?>"><?= e($isl[0]) ?></span>
                        </td>
                        <td><?= e(to_jalali($inv->start_date ?? '')) ?></td>
                        <td>
                            <a href="<?= url('/admin/investment/' . $inv->id) ?>" class="btn btn-xs btn-outline-primary">
                                <i class="material-icons">visibility</i>
                            </a>
                            <?php if ($inv->status === 'active'): ?>
                            <button class="btn btn-xs btn-outline-danger" onclick="suspendInvestment(<?= e($inv->id) ?>)">
                                <i class="material-icons">block</i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="<?= url('/admin/investment?' . \http_build_query(\array_merge($filters, ['page' => $i]))) ?>"
                   class="page-link <?= $i === $currentPage ? 'active' : '' ?>"><?= e($i) ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function suspendInvestment(id) {
    Swal.fire({
        title: 'تعلیق سرمایه‌گذاری', input: 'textarea',
        inputLabel: 'دلیل', inputPlaceholder: 'دلیل تعلیق...',
        showCancelButton: true, confirmButtonText: 'تعلیق', cancelButtonText: 'انصراف',
        confirmButtonColor: '#f44336'
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/investment/') ?>${id}/suspend`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
                body: JSON.stringify({ reason: result.value })
            }).then(r => r.json()).then(res => {
                res.success ? notyf.success(res.message) : notyf.error(res.message);
                if (res.success) setTimeout(() => location.reload(), 1000);
            });
        }
    });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>