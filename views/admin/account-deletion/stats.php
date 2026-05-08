<?php
ob_start();
?>
<div class="admin-page-header">
    <h1>آمار حذف حساب</h1>
    <p class="text-muted">اطلاعات خلاصه درباره درخواست‌های حذف و حجم داده‌های مرتبط با حذف‌ها.</p>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="stat-box stat-box-primary">
            <div class="stat-box-label">درخواست‌های معلق</div>
            <div class="stat-box-value"><?= fa_number($stats['pending_count'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-box stat-box-success">
            <div class="stat-box-label">حساب‌های حذف‌شده</div>
            <div class="stat-box-value"><?= fa_number($stats['deleted_count'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-box stat-box-warning">
            <div class="stat-box-label">حجم داده تقریبی</div>
            <div class="stat-box-value"><?= e($stats['total_data_size'] ?? '0 B') ?></div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-body">
        <h3 class="card-title">جزئیات بیشتر</h3>
        <p>کاربران در حال انقضا: <?= fa_number($stats['expiring_soon'] ?? 0) ?></p>
        <p>این آمار می‌تواند برای اولویت‌بندی بررسی‌های امنیتی مفید باشد.</p>
    </div>
</div>
