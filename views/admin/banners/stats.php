<?php
$pageTitle = 'آمار تبلیغات';
include __DIR__ . '/../../partials/admin/header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1>📊 آمار و گزارش</h1>
        <a href="/admin/banners" class="btn btn-secondary">بازگشت</a>
    </div>

    <div class="stats-overview">
        <div class="stat-box">
            <div class="stat-icon">📢</div>
            <div class="stat-details">
                <div class="stat-number"><?= number_format($stats['total']) ?></div>
                <div class="stat-label">کل بنرها</div>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icon">✅</div>
            <div class="stat-details">
                <div class="stat-number"><?= number_format($stats['active']) ?></div>
                <div class="stat-label">فعال</div>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icon">⏳</div>
            <div class="stat-details">
                <div class="stat-number"><?= number_format($stats['pending']) ?></div>
                <div class="stat-label">در انتظار تایید</div>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icon">👁️</div>
            <div class="stat-details">
                <div class="stat-number"><?= number_format($stats['total_impressions']) ?></div>
                <div class="stat-label">کل نمایش</div>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icon">👆</div>
            <div class="stat-details">
                <div class="stat-number"><?= number_format($stats['total_clicks']) ?></div>
                <div class="stat-label">کل کلیک</div>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icon">📈</div>
            <div class="stat-details">
                <div class="stat-number">
                    <?= $stats['total_impressions'] > 0 ? number_format(($stats['total_clicks'] / $stats['total_impressions']) * 100, 2) : 0 ?>%
                </div>
                <div class="stat-label">نرخ کلیک (CTR)</div>
            </div>
        </div>
    </div>

    <div class="placements-stats">
        <h2>آمار جایگاه‌ها</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>جایگاه</th>
                    <th>صفحه</th>
                    <th>وضعیت</th>
                    <th>بنرهای فعال</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($placements as $p): ?>
                    <tr>
                        <td><?= e($p->title) ?></td>
                        <td><code><?= e($p->page) ?></code></td>
                        <td>
                            <span class="badge <?= $p->is_active ? 'badge-success' : 'badge-secondary' ?>">
                                <?= $p->is_active ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </td>
                        <td><?= $p->active_banners ?? 0 ?> / <?= $p->max_banners ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.stats-overview{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:40px}
.stat-box{background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);display:flex;align-items:center;gap:20px}
.stat-icon{font-size:48px}
.stat-number{font-size:32px;font-weight:bold;color:#333}
.stat-label{font-size:14px;color:#666;margin-top:5px}
.placements-stats{background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1)}
.placements-stats h2{margin:0 0 20px;font-size:20px}
</style>

<?php include __DIR__ . '/../../partials/admin/footer.php'; ?>
