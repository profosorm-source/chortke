<?php
$pageTitle = 'مدیریت جایگاه‌ها';
include __DIR__ . '/../../partials/admin/header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1>📍 جایگاه‌های تبلیغاتی</h1>
        <a href="/admin/banners" class="btn btn-secondary">بازگشت</a>
    </div>

    <div class="placements-grid">
        <?php foreach ($placements as $p): ?>
            <div class="placement-card <?= $p->is_active ? 'active' : 'inactive' ?>">
                <div class="placement-header">
                    <h3><?= e($p->title) ?></h3>
                    <div class="badge <?= $p->is_active ? 'badge-success' : 'badge-secondary' ?>">
                        <?= $p->is_active ? '✅ فعال' : '❌ غیرفعال' ?>
                    </div>
                </div>

                <div class="placement-info">
                    <div class="info-row">
                        <span class="label">کد:</span>
                        <code><?= e($p->slug) ?></code>
                    </div>
                    <div class="info-row">
                        <span class="label">صفحه:</span>
                        <span><?= e($p->page) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">ابعاد:</span>
                        <span><?= e($p->dimensions ?? '-') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">حداکثر:</span>
                        <span><?= $p->max_banners ?> بنر</span>
                    </div>
                    <div class="info-row">
                        <span class="label">فعال:</span>
                        <span class="badge badge-info"><?= $p->active_banners ?? 0 ?></span>
                    </div>
                </div>

                <div class="placement-actions">
                    <form method="POST" action="/admin/banners/placements/toggle" style="display:inline;">
                        <input type="hidden" name="id" value="<?= $p->id ?>">
                        <button type="submit" class="btn btn-sm <?= $p->is_active ? 'btn-warning' : 'btn-success' ?>">
                            <?= $p->is_active ? '⏸️ غیرفعال' : '▶️ فعال' ?>
                        </button>
                    </form>
                    <button class="btn btn-sm btn-primary" onclick="editPlacement(<?= $p->id ?>)">⚙️ تنظیمات</button>
                </div>

                <?php if ($p->description): ?>
                    <div class="placement-desc">
                        <small><?= e($p->description) ?></small>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function editPlacement(id) {
    alert('ویرایش جایگاه ' + id);
}
</script>

<style>
.placements-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px}
.placement-card{background:#fff;border-radius:8px;padding:20px;box-shadow:0 2px 4px rgba(0,0,0,0.1);border-right:4px solid #28a745}
.placement-card.inactive{border-right-color:#dc3545;opacity:0.7}
.placement-header{display:flex;justify-content:space-between;align-items:start;margin-bottom:15px;padding-bottom:15px;border-bottom:1px solid #eee}
.placement-header h3{margin:0;font-size:16px}
.placement-info{margin-bottom:15px}
.info-row{display:flex;justify-content:space-between;padding:5px 0;font-size:13px}
.info-row .label{color:#666}
.placement-actions{display:flex;gap:10px}
.placement-desc{margin-top:15px;padding-top:15px;border-top:1px solid #eee;color:#666}
.badge{padding:4px 8px;border-radius:4px;font-size:12px}
.badge-success{background:#d4edda;color:#155724}
.badge-secondary{background:#e2e3e5;color:#383d41}
.badge-info{background:#d1ecf1;color:#0c5460}
</style>

<?php include __DIR__ . '/../../partials/admin/footer.php'; ?>
