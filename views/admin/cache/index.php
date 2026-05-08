<?php
/** @var array $stats */
$title = 'مدیریت Cache';
$layout = 'admin';
ob_start();
?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0 fw-bold">
            <span class="material-icons align-middle text-primary">cached</span>
            مدیریت Cache
        </h4>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-warning btn-sm" onclick="clearCache('settings')">
                <span class="material-icons align-middle" style="font-size:18px">settings</span>
                پاک‌سازی تنظیمات
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="clearCache('kpi')">
                <span class="material-icons align-middle" style="font-size:18px">analytics</span>
                پاک‌سازی KPI
            </button>
            <button class="btn btn-danger btn-sm" onclick="clearCache('all')">
                <span class="material-icons align-middle" style="font-size:18px">delete_sweep</span>
                پاک‌سازی کامل
            </button>
        </div>
    </div>

    <!-- آمار -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center py-3">
                <span class="material-icons text-primary fs-2">folder</span>
                <h4 class="mb-0 mt-1"><?= number_format($stats['total_files'] ?? 0) ?></h4>
                <small class="text-muted">کل فایل‌های cache</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center py-3">
                <span class="material-icons text-success fs-2">check_circle</span>
                <h4 class="mb-0 mt-1"><?= number_format($stats['valid_files'] ?? 0) ?></h4>
                <small class="text-muted">معتبر</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center py-3">
                <span class="material-icons text-warning fs-2">timer_off</span>
                <h4 class="mb-0 mt-1"><?= number_format($stats['expired_files'] ?? 0) ?></h4>
                <small class="text-muted">منقضی</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center py-3">
                <span class="material-icons text-info fs-2">storage</span>
                <h4 class="mb-0 mt-1"><?= number_format($stats['total_size_kb'] ?? 0, 1) ?> KB</h4>
                <small class="text-muted">حجم کل</small>
            </div>
        </div>
    </div>

    <!-- کلیدهای شناخته‌شده -->
    <div class="row g-3 mb-4">
        <?php
        $namedCaches = [
            ['key' => 'settings_all',         'label' => 'تنظیمات سایت',  'icon' => 'settings',   'color' => 'warning'],
            ['key' => 'kpi_dashboard_summary', 'label' => 'KPI داشبورد',   'icon' => 'analytics',  'color' => 'info'],
            ['key' => 'kpi_weekly_report',     'label' => 'گزارش هفتگی',  'icon' => 'bar_chart',  'color' => 'primary'],
        ];
        $cacheKeys = array_column($stats['keys'] ?? [], null, 'key');
        foreach ($namedCaches as $nc):
            $exists = isset($cacheKeys[$nc['key']]);
        ?>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="material-icons text-<?= $nc['color'] ?> fs-2"><?= $nc['icon'] ?></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= $nc['label'] ?></div>
                        <code class="small text-muted"><?= $nc['key'] ?></code>
                    </div>
                    <?php if ($exists): ?>
                        <span class="badge bg-success">فعال</span>
                        <button class="btn btn-sm btn-outline-danger p-1"
                                onclick="clearCacheKey('<?= $nc['key'] ?>')" title="حذف">
                            <span class="material-icons" style="font-size:16px">delete</span>
                        </button>
                    <?php else: ?>
                        <span class="badge bg-secondary">خالی</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- جدول همه کلیدها -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">همه کلیدهای Cache</h6>
            <small class="text-muted"><?= count($stats['keys'] ?? []) ?> کلید</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 small align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>کلید</th>
                            <th>انقضا</th>
                            <th>حجم</th>
                            <th>وضعیت</th>
                            <th width="60"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats['keys'])): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">
                                <span class="material-icons d-block fs-1 mb-2 text-success">check_circle</span>
                                Cache خالی است
                            </td></tr>
                        <?php else: ?>
                        <?php foreach ($stats['keys'] as $item): $item = (object)$item; ?>
                        <tr>
                            <td><code><?= e($item->key) ?></code></td>
                            <td class="text-muted">
                                <?php if (($item->expire_at ?? 0) > time()): ?>
                                    <?= e(date('Y/m/d H:i', $item->expire_at)) ?>
                                    <span class="text-success small">
                                        (<?= e(round(($item->expire_at - time()) / 60)) ?> دقیقه دیگر)
                                    </span>
                                <?php elseif ($item->expire_at): ?>
                                    <span class="text-danger">منقضی</span>
                                <?php else: ?>
                                    <span class="text-muted">بدون انقضا</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted">
                                <?= $item->size_bytes > 0 ? number_format($item->size_bytes) . ' B' : '-' ?>
                            </td>
                            <td>
                                <?php if (($item->expire_at ?? 0) > time() || !$item->expire_at): ?>
                                    <span class="badge bg-success">معتبر</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">منقضی</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-danger p-1"
                                        onclick="clearCacheKey('<?= e(addslashes($item->key)) ?>')"
                                        title="حذف این کلید">
                                    <span class="material-icons" style="font-size:16px">delete</span>
                                </button>
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

<script>
const csrfToken = '<?= csrf_token() ?>';

function clearCache(type) {
    const labels = {all: 'کل cache سایت', settings: 'cache تنظیمات', kpi: 'cache KPI'};
    Swal.fire({
        title: 'پاک‌سازی ' + labels[type],
        text: 'آیا مطمئن هستید؟',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'بله، پاک شود',
        cancelButtonText: 'انصراف',
    }).then(result => {
        if (!result.isConfirmed) return;
        fetch('<?= url('/admin/cache/clear') ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify({type})
        })
        .then(r => r.json())
        .then(d => {
            notyf.success(d.message || 'Cache پاک شد');
            setTimeout(() => location.reload(), 1000);
        })
        .catch(() => notyf.error('خطا در پاک‌سازی'));
    });
}

function clearCacheKey(key) {
    fetch('<?= url('/admin/cache/forget') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
        body: JSON.stringify({key})
    })
    .then(r => r.json())
    .then(d => {
        notyf.success(`کلید "${key}" حذف شد`);
        setTimeout(() => location.reload(), 500);
    })
    .catch(() => notyf.error('خطا'));
}
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>