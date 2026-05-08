<?php
$title = 'مدیریت سطح‌بندی کاربران';
$layout = 'admin';
$session = \Core\Session::getInstance();
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons text-primary">workspace_premium</i>
                مدیریت سطح‌بندی کاربران
            </h4>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('/admin/levels/history') ?>" class="btn btn-outline-info btn-sm">
                <i class="material-icons" style="font-size:16px;vertical-align:middle;">history</i>
                تاریخچه تغییرات
            </a>
            <a href="<?= url('/admin/levels/create') ?>" class="btn btn-primary btn-sm">
                <i class="material-icons" style="font-size:16px;vertical-align:middle;">add_circle</i>
                ایجاد سطح جدید
            </a>
        </div>
    </div>
</div>

<?php if ($flash = $session->getFlash('success')): ?>
<div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
    <i class="material-icons" style="font-size:18px;vertical-align:middle;">check_circle</i>
    <?= e($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mt-3 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ترتیب</th>
                        <th>سطح</th>
                        <th>شرایط فعالیت</th>
                        <th>قیمت خرید</th>
                        <th>پاداش‌ها</th>
                        <th>کاربران</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($levels as $level): ?>
                    <tr>
                        <td><?= e($level->sort_order) ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <i class="material-icons" style="color:<?= e($level->color) ?>;font-size:24px;"><?= e($level->icon ?? 'workspace_premium') ?></i>
                                <strong style="color:<?= e($level->color) ?>;"><?= e($level->name) ?></strong>
                                <code style="font-size:10px;"><?= e($level->slug) ?></code>
                            </div>
                        </td>
                        <td style="font-size:11px;">
                            <?= e($level->min_active_days) ?> روز |
                            <?= e($level->min_completed_tasks) ?> تسک |
                            <?= number_format($level->min_total_earning) ?> ت
                        </td>
                        <td style="font-size:11px;">
                            <?php if ($level->purchase_price_irt > 0): ?>
                                <?= number_format($level->purchase_price_irt) ?> تومان
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                            <?php if ($level->purchase_price_usdt > 0): ?>
                                <br><?= number_format($level->purchase_price_usdt, 2) ?> USDT
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;">
                            درآمد +<?= e($level->earning_bonus_percent) ?>% |
                            معرفی +<?= e($level->referral_bonus_percent) ?>%
                            <?php if ($level->priority_support): ?><br>⭐ پشتیبانی VIP<?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-info"><?= number_format($userCounts[$level->slug] ?? 0) ?> نفر</span>
                        </td>
                        <td>
                            <?php if ($level->is_active): ?>
                                <span class="badge badge-success">فعال</span>
                            <?php else: ?>
                                <span class="badge badge-danger">غیرفعال</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= url('/admin/levels/' . $level->id . '/edit') ?>" class="btn btn-sm btn-outline-primary" title="ویرایش">
                                    <i class="material-icons" style="font-size:16px;">edit</i>
                                </a>
                                <?php if ($level->slug !== 'bronze'): ?>
                                <button type="button"
                                    class="btn btn-sm btn-outline-danger btn-delete-level"
                                    data-id="<?= e($level->id) ?>"
                                    data-name="<?= e($level->name) ?>"
                                    title="حذف">
                                    <i class="material-icons" style="font-size:16px;">delete</i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="alert" style="background:linear-gradient(135deg,#fff3e0 0%,#ffe0b2 100%);border-right:4px solid #ffa726;">
    <div style="font-size:12px;color:#e65100;">
        <i class="material-icons" style="font-size:16px;vertical-align:middle;color:#f57c00;">info</i>
        <strong>نکات:</strong>
        <ul class="mb-0 mt-1" style="padding-right:20px;">
            <li>سطح «برنز» سطح پیش‌فرض همه کاربران است و حذف‌شدنی نیست.</li>
            <li>اگر کاربر در ماه کمتر از <strong><?= setting('level_downgrade_inactive_days', 3) ?> روز</strong> فعالیت داشته باشد، سطحش به برنز سقوط می‌کند.</li>
            <li>سطوح خریداری‌شده تا انقضا حفظ می‌شوند.</li>
        </ul>
    </div>
</div>

<script>
document.querySelectorAll('.btn-delete-level').forEach(btn => {
    btn.addEventListener('click', function() {
        const id   = this.dataset.id;
        const name = this.dataset.name;
        if (!confirm('آیا مطمئن هستید که سطح «' + name + '» را حذف کنید؟\nاین عمل قابل بازگشت نیست.')) return;

        fetch(`<?= url('/admin/levels/') ?>${id}/delete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= csrf_token() ?>' },
            body: JSON.stringify({ csrf_token: '<?= csrf_token() ?>' }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                this.closest('tr').remove();
            } else {
                alert(data.message || 'خطا در حذف سطح');
            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
    });
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>