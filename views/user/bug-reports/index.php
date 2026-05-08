<?php
 $title = 'گزارش‌های من'; $layout = 'user'; ob_start();
$statusLabels = [
    'open' => ['باز', 'bg-primary'], 'in_progress' => ['در حال بررسی', 'bg-info'],
    'resolved' => ['حل شده', 'bg-success'], 'closed' => ['بسته شده', 'bg-secondary'],
    'duplicate' => ['تکراری', 'bg-warning'], 'wont_fix' => ['رد شده', 'bg-danger'],
];
$priorityLabels = [
    'low' => ['کم', 'bg-secondary'], 'normal' => ['متوسط', 'bg-info'],
    'high' => ['بالا', 'bg-warning'], 'critical' => ['بحرانی', 'bg-danger'],
];
$categoryLabels = [
    'ui_issue' => 'ظاهری', 'functional' => 'عملکردی', 'payment' => 'پرداخت',
    'security' => 'امنیتی', 'performance' => 'سرعت', 'content' => 'محتوا', 'other' => 'سایر',
];
?>

<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><span class="material-icons me-1" style="vertical-align:middle;">bug_report</span> گزارش‌های مشکل من</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($reports)): ?>
                <div class="text-center py-5 text-muted">
                    <span class="material-icons" style="font-size:48px;">check_circle_outline</span>
                    <p class="mt-2">هنوز گزارشی ثبت نکرده‌اید</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>دسته</th>
                                <th>توضیحات</th>
                                <th>اولویت</th>
                                <th>وضعیت</th>
                                <th>تاریخ</th>
                                <th>پاسخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $r): ?>
                                <tr style="cursor:pointer;" onclick="window.location='<?= url("/bug-reports/{$r->id}") ?>'">
                                    <td><?= e($r->id) ?></td>
                                    <td><span class="badge bg-outline-info"><?= e($categoryLabels[$r->category] ?? $r->category) ?></span></td>
                                    <td><?= e(\mb_strimwidth($r->description, 0, 60, '...')) ?></td>
                                    <td>
                                        <?php $pri = $priorityLabels[$r->priority] ?? ['?', 'bg-secondary']; ?>
                                        <span class="badge <?= e($pri[1]) ?>"><?= e($pri[0]) ?></span>
                                    </td>
                                    <td>
                                        <?php $st = $statusLabels[$r->status] ?? ['?', 'bg-secondary']; ?>
                                        <span class="badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span>
                                    </td>
                                    <td><small><?= e(to_jalali($r->created_at ?? '')) ?></small></td>
                                    <td>
                                        <?php if ($r->comment_count > 0): ?>
                                            <span class="badge bg-success"><?= e($r->comment_count) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>