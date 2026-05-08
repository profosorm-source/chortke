<?php
$title = 'گزارش‌های باگ';
$layout = 'admin';
$reports = $reports ?? [];
$currentPage = $currentPage ?? 1;
$totalPages = $totalPages ?? 1;
ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <span class="material-icons align-middle me-2">bug_report</span>
        گزارش‌های باگ کاربران
    </h4>
</div>

<?php if (empty($reports)): ?>
<div class="empty-state">
    <span class="material-icons">bug_report</span>
    <p>هیچ گزارش باگی یافت نشد</p>
</div>
<?php else: ?>
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>#</th>
                    <th>کاربر</th>
                    <th>عنوان</th>
                    <th>اولویت</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports as $r): ?>
                <tr>
                    <td><?= e($r->id) ?></td>
                    <td><?= e($r->user_name ?? 'ناشناس') ?></td>
                    <td><?= e(mb_substr($r->title, 0, 60)) ?><?= mb_strlen($r->title) > 60 ? '...' : '' ?></td>
                    <td>
                        <span class="badge badge-<?= $r->priority === 'high' ? 'danger' : ($r->priority === 'medium' ? 'warning' : 'secondary') ?>">
                            <?= e($r->priority_label ?? $r->priority) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-<?= $r->status === 'resolved' ? 'success' : ($r->status === 'open' ? 'danger' : 'warning') ?>">
                            <?= e($r->status_label ?? $r->status) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= e(to_jalali($r->created_at)) ?></td>
                    <td>
                        <a href="<?= url('/admin/bug-reports/' . $r->id) ?>" class="btn btn-view-custom btn-sm">
                            <span class="material-icons" style="font-size:14px">visibility</span>
                            مشاهده
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-4 d-flex justify-content-center">
    <ul class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?= $i == $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="<?= url('/admin/bug-reports?page=' . $i) ?>"><?= e($i) ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>
