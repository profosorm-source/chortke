<?php
ob_start();
?>
<div class="admin-page-header">
    <h1>مدیریت پشتیبان‌گیری</h1>
    <p class="text-muted">ایجاد، مدیریت و پاک‌سازی پشتیبان‌های دیتابیس.</p>
</div>

<!-- آمار پشتیبان‌ها -->
<?php if (isset($stats) && $stats['success']): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-box stat-box-primary">
            <div class="stat-box-label">کل پشتیبان‌ها</div>
            <div class="stat-box-value"><?= fa_number($stats['total_backups'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box stat-box-success">
            <div class="stat-box-label">حجم کل</div>
            <div class="stat-box-value"><?= e($stats['total_size'] ?? '0 B') ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box stat-box-info">
            <div class="stat-box-label">آخرین پشتیبان</div>
            <div class="stat-box-value small"><?= $stats['last_backup'] ? jdate('Y/m/d', strtotime($stats['last_backup'])) : 'ندارد' ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box stat-box-warning">
            <div class="stat-box-label">اولین پشتیبان</div>
            <div class="stat-box-value small"><?= $stats['first_backup'] ? jdate('Y/m/d', strtotime($stats['first_backup'])) : 'ندارد' ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- دکمه‌های اقدام -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <form action="<?= url('/admin/backups/create') ?>" method="POST">
                    <?= csrf_field() ?>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" name="description" placeholder="توضیح برای پشتیبان (اختیاری)">
                        <button type="submit" class="btn btn-success">ایجاد پشتیبان جدید</button>
                    </div>
                </form>
            </div>
            <div class="col-md-6 text-end">
                <a href="<?= url('/admin/backups/stats') ?>" class="btn btn-info">آمار تفصیلی</a>
            </div>
        </div>
    </div>
</div>

<!-- لیست پشتیبان‌ها -->
<div class="card">
    <div class="card-body">
        <?php if (empty($backups)): ?>
            <div class="alert alert-info">هیچ پشتیبانی ثبت نشده است.</div>
        <?php else: ?>
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>نام فایل</th>
                        <th>حجم</th>
                        <th>توضیح</th>
                        <th>تاریخ ایجاد</th>
                        <th>اقدامات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?= e($backup['filename'] ?? 'N/A') ?></td>
                            <td><?= e($backup['size'] ?? 'N/A') ?></td>
                            <td><?= e($backup['description'] ?? '-') ?></td>
                            <td><?= isset($backup['created_at']) ? jdate('Y/m/d H:i', strtotime($backup['created_at'])) : 'N/A' ?></td>
                            <td>
                                <!-- فعلاً restore محدود است -->
                                <span class="badge badge-secondary">محدود</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- پاک‌سازی پشتیبان‌های قدیمی -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">پاک‌سازی پشتیبان‌های قدیمی</h5>
    </div>
    <div class="card-body">
        <form action="<?= url('/admin/backups/cleanup') ?>" method="POST" class="d-flex align-items-center gap-2">
            <?= csrf_field() ?>
            <label for="days_to_keep" class="form-label mb-0">پشتیبان‌های قدیمی‌تر از:</label>
            <input type="number" name="days_to_keep" id="days_to_keep" class="form-control" value="30" style="max-width: 100px;">
            <span class="text-muted">روز</span>
            <button type="submit" class="btn btn-danger">پاک‌سازی</button>
        </form>
        <small class="text-muted d-block mt-2">این عملیات پشتیبان‌های قدیمی‌تر از مدت زمان مشخص شده را حذف خواهد کرد.</small>
    </div>
</div>
