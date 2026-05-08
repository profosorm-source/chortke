<?php
$title = 'داشبورد لاگ‌های پیشرفته';
$layout = 'admin';
ob_start();
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0">📊 داشبورد لاگ‌ها</h2>
            <p class="text-muted mb-0">مانیتورینگ لحظه‌ای سیستم</p>
        </div>
        <div>
            <select class="form-select" onchange="location.href='?period='+this.value">
                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>امروز</option>
                <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>دیروز</option>
                <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>هفته اخیر</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>ماه اخیر</option>
            </select>
        </div>
    </div>

    <!-- کارت‌های آماری اصلی -->
    <div class="row g-3 mb-4">
        <!-- کل خطاها -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">کل خطاها</p>
                            <h3 class="mb-0"><?= number_format($todayStats['total_errors']) ?></h3>
                            <?php if (isset($comparison['errors_change'])): ?>
                                <small class="text-<?= $comparison['errors_change']['direction'] === 'down' ? 'success' : 'danger' ?>">
                                    <?= $comparison['errors_change']['direction'] === 'up' ? '↑' : '↓' ?>
                                    <?= $comparison['errors_change']['percent'] ?>% نسبت به دیروز
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="text-danger">
                            <i class="material-icons" style="font-size: 48px;">error_outline</i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- خطاهای Critical -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">خطاهای بحرانی</p>
                            <h3 class="mb-0 text-danger"><?= number_format($todayStats['critical_errors']) ?></h3>
                            <small class="text-muted">نیاز به توجه فوری</small>
                        </div>
                        <div class="text-danger">
                            <i class="material-icons" style="font-size: 48px;">warning</i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- درخواست‌های کند -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">درخواست‌های کند</p>
                            <h3 class="mb-0 text-warning"><?= number_format($todayStats['slow_requests']) ?></h3>
                            <small class="text-muted">
                                میانگین: <?= $performanceStats['avg_time'] ?> ms
                            </small>
                        </div>
                        <div class="text-warning">
                            <i class="material-icons" style="font-size: 48px;">schedule</i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- هشدارهای فعال -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">هشدارهای فعال</p>
                            <h3 class="mb-0 text-info"><?= number_format($todayStats['active_alerts']) ?></h3>
                            <small class="text-muted">نیاز به بررسی</small>
                        </div>
                        <div class="text-info">
                            <i class="material-icons" style="font-size: 48px;">notifications_active</i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- پرتکرارترین خطاها -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">🔥 پرتکرارترین خطاها</h5>
                    <a href="/admin/logs/errors" class="btn btn-sm btn-outline-primary">مشاهده همه</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>خطا</th>
                                    <th>فایل</th>
                                    <th>سطح</th>
                                    <th>تعداد تکرار</th>
                                    <th>آخرین بار</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($errorStats['top_errors'])): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="material-icons">check_circle</i>
                                            <p class="mb-0 mt-2">هیچ خطایی ثبت نشده</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($errorStats['top_errors'] as $error): ?>
                                        <tr>
                                            <td>
                                                <a href="/admin/logs/error-details?id=<?= $error->id ?>" 
                                                   class="text-decoration-none">
                                                    <?= e(mb_substr($error->message, 0, 60)) ?>...
                                                </a>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php if ($error->file_path): ?>
                                                        <?= e(basename($error->file_path)) ?>:<?= $error->line_number ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= e(
    $error->level === 'CRITICAL' ? 'danger' :
    ($error->level === 'ERROR' ? 'warning' : 'secondary')
) ?>">
                                                    <?= $error->level ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong class="text-danger">×<?= number_format($error->occurrence_count) ?></strong>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= date('H:i', strtotime($error->last_occurred_at)) ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- هشدارهای اخیر -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">🔔 هشدارهای اخیر</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($activeAlerts)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="material-icons" style="font-size: 48px;">check_circle</i>
                            <p class="mb-0">همه چیز عالیه!</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($activeAlerts, 0, 5) as $alert): ?>
                                <div class="list-group-item px-0">
                                    <div class="d-flex align-items-start">
                                        <div class="me-2">
                                            <?php
                                            $icon = match($alert->severity) {
                                                'critical' => '🔴',
                                                'high' => '🟠',
                                                'medium' => '🟡',
                                                default => '🔵'
                                            };
                                            echo $icon;
                                            ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?= e($alert->title) ?></h6>
                                            <p class="mb-1 small text-muted">
                                                <?= e(mb_substr($alert->message, 0, 100)) ?>
                                            </p>
                                            <small class="text-muted">
                                                <?= date('H:i', strtotime($alert->created_at)) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-3">
                            <a href="/admin/logs/alerts" class="btn btn-sm btn-outline-primary">
                                مشاهده همه هشدارها
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- پیش‌بینی مشکلات -->
    <?php if (!empty($predictions)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm border-start border-warning border-4">
                    <div class="card-header bg-warning bg-opacity-10">
                        <h5 class="mb-0">⚠️ هشدارهای پیش‌بینی شده</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($predictions as $prediction): ?>
                            <div class="alert alert-<?= e(
    $prediction['severity'] === 'critical' ? 'danger' :
    ($prediction['severity'] === 'high' ? 'warning' : 'info')) ?>">
                                <strong><?= e($prediction['message']) ?></strong>
                                <p class="mb-0 small mt-1">
                                    نوع: <?= e($prediction['type']) ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- نمودار عملکرد -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">📈 روند خطاها</h5>
                </div>
                <div class="card-body">
                    <canvas id="errorsChart" height="200"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">⚡ عملکرد سیستم</h5>
                </div>
                <div class="card-body">
                    <canvas id="performanceChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- دسترسی سریع -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="mb-3">دسترسی سریع</h5>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <a href="/admin/logs/errors" class="btn btn-outline-danger w-100">
                                <i class="material-icons align-middle">error_outline</i>
                                مدیریت خطاها
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="/admin/logs/performance" class="btn btn-outline-warning w-100">
                                <i class="material-icons align-middle">speed</i>
                                مانیتورینگ عملکرد
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="/admin/logs/alerts" class="btn btn-outline-info w-100">
                                <i class="material-icons align-middle">notifications</i>
                                هشدارها
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="/admin/logs/notification-settings" class="btn btn-outline-secondary w-100">
                                <i class="material-icons align-middle">settings</i>
                                تنظیمات
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// نمودار خطاها
fetch('/admin/logs/api-stats?type=errors&period=<?= $period ?>')
    .then(r => r.json())
    .then(data => {
        const ctx = document.getElementById('errorsChart');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => d.date),
                datasets: [{
                    label: 'خطاها',
                    data: data.map(d => d.count),
                    borderColor: 'rgb(220, 53, 69)',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    });

// نمودار عملکرد
fetch('/admin/logs/api-stats?type=performance&period=<?= $period ?>')
    .then(r => r.json())
    .then(data => {
        const ctx = document.getElementById('performanceChart');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => d.date),
                datasets: [{
                    label: 'زمان اجرا (ms)',
                    data: data.map(d => d.avg_time),
                    borderColor: 'rgb(13, 110, 253)',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    });

// بروزرسانی خودکار هر 30 ثانیه
setInterval(() => {
    location.reload();
}, 30000);
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>
