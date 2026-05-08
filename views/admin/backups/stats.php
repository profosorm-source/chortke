<?php
ob_start();
?>
<div class="admin-page-header">
    <h1>آمار پشتیبان‌گیری</h1>
    <p class="text-muted">تفصیلات و آمار کلی پشتیبان‌های دیتابیس.</p>
</div>

<?php if (isset($stats) && $stats['success']): ?>
<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">آمار کلی</h5>
                <ul class="list-unstyled">
                    <li class="mb-3">
                        <strong>کل پشتیبان‌ها:</strong>
                        <span class="badge badge-primary"><?= fa_number($stats['total_backups'] ?? 0) ?></span>
                    </li>
                    <li class="mb-3">
                        <strong>حجم کل:</strong>
                        <span class="badge badge-success"><?= e($stats['total_size'] ?? '0 B') ?></span>
                    </li>
                    <li class="mb-3">
                        <strong>آخرین پشتیبان:</strong>
                        <span><?= $stats['last_backup'] ? jdate('Y/m/d H:i', strtotime($stats['last_backup'])) : 'ندارد' ?></span>
                    </li>
                    <li>
                        <strong>اولین پشتیبان:</strong>
                        <span><?= $stats['first_backup'] ? jdate('Y/m/d', strtotime($stats['first_backup'])) : 'ندارد' ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">توصیات</h5>
                <ul class="list-unstyled">
                    <li class="mb-2">✓ پشتیبان‌گیری روزانه انجام می‌شود</li>
                    <li class="mb-2">✓ پشتیبان‌های قدیمی‌تر از ۳۰ روز حذف می‌شوند</li>
                    <li class="mb-2">✓ پشتیبان‌ها به طور خودکار فشرده می‌شوند</li>
                    <li class="mb-2">✓ تغییرات منظم ثبت می‌شوند</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">بازگشت</h5>
        <a href="<?= url('/admin/backups') ?>" class="btn btn-secondary">بازگشت به لیست</a>
    </div>
</div>
<?php else: ?>
<div class="alert alert-danger">خطا: دریافت آمار ناموفق بود</div>
<?php endif; ?>
