<?php
$pageTitle = 'مدیریت تبلیغات';
include __DIR__ . '/../../partials/admin/header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1>📢 مدیریت تبلیغات</h1>
        <div class="actions">
            <a href="/admin/banners/create" class="btn btn-primary">افزودن بنر</a>
            <a href="/admin/banners/stats" class="btn btn-secondary">آمار</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📝</div>
            <div class="stat-info">
                <div class="stat-label">کل بنرها</div>
                <div class="stat-value"><?= number_format($stats['total']) ?></div>
            </div>
        </div>
        <div class="stat-card success">
            <div class="stat-icon">✅</div>
            <div class="stat-info">
                <div class="stat-label">فعال</div>
                <div class="stat-value"><?= number_format($stats['active']) ?></div>
            </div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon">⏳</div>
            <div class="stat-info">
                <div class="stat-label">در انتظار</div>
                <div class="stat-value"><?= number_format($stats['pending']) ?></div>
            </div>
        </div>
        <div class="stat-card info">
            <div class="stat-icon">👆</div>
            <div class="stat-info">
                <div class="stat-label">کل کلیک</div>
                <div class="stat-value"><?= number_format($stats['total_clicks']) ?></div>
            </div>
        </div>
    </div>

    <div class="filters-box">
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label>نوع:</label>
                <select name="banner_type">
                    <option value="">همه</option>
                    <option value="system" <?= ($filters['banner_type'] ?? '') === 'system' ? 'selected' : '' ?>>سیستمی</option>
                    <option value="startup" <?= ($filters['banner_type'] ?? '') === 'startup' ? 'selected' : '' ?>>استارتاپی</option>
                    <option value="user" <?= ($filters['banner_type'] ?? '') === 'user' ? 'selected' : '' ?>>کاربری</option>
                    <option value="promo" <?= ($filters['banner_type'] ?? '') === 'promo' ? 'selected' : '' ?>>تبلیغاتی</option>
                </select>
            </div>

            <div class="filter-group">
                <label>جایگاه:</label>
                <select name="placement">
                    <option value="">همه</option>
                    <?php foreach ($placements as $p): ?>
                        <option value="<?= $p->slug ?>" <?= ($filters['placement'] ?? '') === $p->slug ? 'selected' : '' ?>>
                            <?= e($p->title) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>وضعیت:</label>
                <select name="is_active">
                    <option value="">همه</option>
                    <option value="1" <?= ($filters['is_active'] ?? '') === '1' ? 'selected' : '' ?>>فعال</option>
                    <option value="0" <?= ($filters['is_active'] ?? '') === '0' ? 'selected' : '' ?>>غیرفعال</option>
                </select>
            </div>

            <div class="filter-group">
                <label>جستجو:</label>
                <input type="text" name="search" placeholder="عنوان..." value="<?= e($filters['search'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-primary">فیلتر</button>
            <a href="/admin/banners" class="btn btn-secondary">پاک کردن</a>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th width="60">#</th>
                <th width="100">تصویر</th>
                <th>عنوان</th>
                <th>جایگاه</th>
                <th width="100">نوع</th>
                <th width="100">وضعیت</th>
                <th width="120">آمار</th>
                <th width="180">عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($banners)): ?>
                <tr><td colspan="8" class="text-center">بنری یافت نشد</td></tr>
            <?php else: ?>
                <?php foreach ($banners as $banner): ?>
                    <tr>
                        <td><?= $banner->id ?></td>
                        <td>
                            <?php if ($banner->image_path): ?>
                                <img src="<?= e($banner->image_path) ?>" alt="" style="max-width:80px;max-height:50px;object-fit:cover;">
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= e($banner->title) ?></strong>
                            <?php if ($banner->user_name): ?>
                                <br><small class="text-muted">کاربر: <?= e($banner->user_name) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-info"><?= e($banner->placement) ?></span></td>
                        <td><?= banner_type_label($banner->banner_type) ?></td>
                        <td><?= banner_status_badge($banner) ?></td>
                        <td>
                            <small>
                                👁️ <?= number_format($banner->impressions) ?><br>
                                👆 <?= number_format($banner->clicks) ?><br>
                                📈 <?= number_format($banner->ctr, 1) ?>%
                            </small>
                        </td>
                        <td class="actions">
                            <?php if (in_array($banner->banner_type, ['startup', 'user']) && !$banner->approved_at): ?>
                                <form method="POST" action="/admin/banners/approve" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $banner->id ?>">
                                    <button type="submit" class="btn btn-sm btn-success">✅</button>
                                </form>
                                <button type="button" class="btn btn-sm btn-danger" onclick="rejectBanner(<?= $banner->id ?>)">❌</button>
                            <?php endif; ?>
                            <a href="/admin/banners/edit?id=<?= $banner->id ?>" class="btn btn-sm btn-primary">✏️</a>
                            <form method="POST" action="/admin/banners/delete" style="display:inline;" onsubmit="return confirm('حذف شود؟')">
                                <input type="hidden" name="id" value="<?= $banner->id ?>">
                                <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php
    $totalPages = ceil($total / $perPage);
    if ($totalPages > 1):
    ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="/admin/banners?page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function rejectBanner(id) {
    const reason = prompt('دلیل رد:');
    if (!reason) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/banners/reject';
    form.innerHTML = '<input type="hidden" name="id" value="' + id + '"><input type="hidden" name="reason" value="' + reason + '">';
    document.body.appendChild(form);
    form.submit();
}
</script>

<style>
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:30px}
.stat-card{background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);display:flex;align-items:center;gap:15px}
.stat-icon{font-size:40px}
.stat-value{font-size:28px;font-weight:bold;color:#333}
.stat-label{font-size:14px;color:#666}
.filters-box{background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;box-shadow:0 2px 4px rgba(0,0,0,0.1)}
.filters-form{display:flex;gap:15px;flex-wrap:wrap;align-items:end}
.filter-group{flex:1;min-width:150px}
.filter-group label{display:block;margin-bottom:5px;font-size:13px;color:#666}
.filter-group select,.filter-group input{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px}
</style>

<?php include __DIR__ . '/../../partials/admin/footer.php'; ?>
