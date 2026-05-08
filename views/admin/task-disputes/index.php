<?php
// views/admin/task-disputes/index.php
$title = 'اختلافات تسک‌ها';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-task-disputes.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">gavel</i> اختلافات تسک‌ها</h4>
</div>

<div class="filter-card">
    <form method="GET" action="<?= url('/admin/task-disputes') ?>" class="filter-form">
        <select name="status" class="form-control-sm">
            <option value="">همه</option>
            <option value="open" <?= ($filters['status'] ?? '') === 'open' ? 'selected' : '' ?>>باز</option>
            <option value="under_review" <?= ($filters['status'] ?? '') === 'under_review' ? 'selected' : '' ?>>در حال بررسی</option>
            <option value="resolved_for_executor" <?= ($filters['status'] ?? '') === 'resolved_for_executor' ? 'selected' : '' ?>>به نفع انجام‌دهنده</option>
            <option value="resolved_for_advertiser" <?= ($filters['status'] ?? '') === 'resolved_for_advertiser' ? 'selected' : '' ?>>به نفع سفارش‌دهنده</option>
        </select>
        <button type="submit" class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
    </form>
    <span class="filter-count"><?= number_format($total) ?> مورد</span>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>تسک</th>
                <th>باز‌شده توسط</th>
                <th>دلیل</th>
                <th>جریمه</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($disputes as $d): ?>
                <tr>
                    <td><?= e($d->id) ?></td>
                    <td><?= e($d->ad_title ?? '—') ?></td>
                    <td>
                        <?= e($d->opener_name ?? '') ?>
                        <small>(<?= $d->opened_by === 'executor' ? 'انجام‌دهنده' : 'سفارش‌دهنده' ?>)</small>
                    </td>
                    <td><?= e(mb_substr($d->reason, 0, 60)) ?><?= mb_strlen($d->reason) > 60 ? '...' : '' ?></td>
                    <td><?= $d->penalty_amount > 0 ? number_format($d->penalty_amount) : '—' ?></td>
                    <td><span class="badge badge-<?= e(task_dispute_status_badge($d->status)) ?>"><?= e(task_dispute_status_label($d->status)) ?></span></td>
                    <td><?= to_jalali($d->created_at) ?></td>
                    <td>
                        <a href="<?= url('/admin/task-disputes/' . $d->id) ?>" class="btn btn-xs btn-outline-secondary">
                            <i class="material-icons">visibility</i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        // فقط پارامترهای مجاز را در URL pagination قرار می‌دهیم
        $allowedQs = array_filter([
            'status' => e($_GET['status'] ?? '', ENT_QUOTES, 'UTF-8'),
            'search' => e($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8'),
        ]);
        for ($i = 1; $i <= $totalPages; $i++):
            $qs = $allowedQs;
            $qs['page'] = $i;
        ?>
            <a href="<?= url('/admin/task-disputes?' . http_build_query($qs)) ?>" class="page-link <?= $i===$page?'active':'' ?>"><?= e($i) ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>