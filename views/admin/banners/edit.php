<?php
$pageTitle = 'ویرایش بنر';
include __DIR__ . '/../../partials/admin/header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1>ویرایش بنر</h1>
        <a href="/admin/banners" class="btn btn-secondary">بازگشت</a>
    </div>

    <div class="form-container">
        <form method="POST" action="/admin/banners/update" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= $banner->id ?>">

            <div class="form-group">
                <label>عنوان *</label>
                <input type="text" name="title" value="<?= e($banner->title) ?>" required class="form-control">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>نوع بنر</label>
                    <input type="text" value="<?= banner_type_label($banner->banner_type) ?>" readonly class="form-control">
                </div>

                <?php if ($banner->category): ?>
                    <div class="form-group">
                        <label>دسته‌بندی</label>
                        <select name="category" class="form-control">
                            <option value="">انتخاب کنید</option>
                            <option value="startup" <?= $banner->category === 'startup' ? 'selected' : '' ?>>استارتاپ</option>
                            <option value="ngo" <?= $banner->category === 'ngo' ? 'selected' : '' ?>>NGO</option>
                            <option value="educational" <?= $banner->category === 'educational' ? 'selected' : '' ?>>آموزشی</option>
                        </select>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>جایگاه *</label>
                <select name="placement" required class="form-control">
                    <?php foreach ($placements as $p): ?>
                        <option value="<?= $p->slug ?>" <?= $banner->placement === $p->slug ? 'selected' : '' ?>>
                            <?= e($p->title) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <?php if ($banner->image_path): ?>
                    <div style="margin-bottom:10px;">
                        <img src="<?= e($banner->image_path) ?>" alt="" style="max-width:300px;max-height:150px;border-radius:4px;">
                    </div>
                <?php endif; ?>
                <label>تصویر جدید (اختیاری)</label>
                <input type="file" name="image" accept="image/*" class="form-control">
            </div>

            <div class="form-group">
                <label>لینک</label>
                <input type="url" name="link" value="<?= e($banner->link ?? '') ?>" class="form-control">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ شروع</label>
                    <input type="datetime-local" name="start_date" 
                           value="<?= $banner->start_date ? date('Y-m-d\TH:i', strtotime($banner->start_date)) : '' ?>" 
                           class="form-control">
                </div>

                <div class="form-group">
                    <label>تاریخ پایان</label>
                    <input type="datetime-local" name="end_date" 
                           value="<?= $banner->end_date ? date('Y-m-d\TH:i', strtotime($banner->end_date)) : '' ?>" 
                           class="form-control">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>ترتیب نمایش</label>
                    <input type="number" name="sort_order" value="<?= $banner->sort_order ?>" class="form-control">
                </div>

                <div class="form-group">
                    <label>باز شدن در</label>
                    <select name="target" class="form-control">
                        <option value="_blank" <?= $banner->target === '_blank' ? 'selected' : '' ?>>پنجره جدید</option>
                        <option value="_self" <?= $banner->target === '_self' ? 'selected' : '' ?>>همین پنجره</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>متن جایگزین (alt)</label>
                <input type="text" name="alt_text" value="<?= e($banner->alt_text ?? '') ?>" class="form-control">
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1" <?= $banner->is_active ? 'checked' : '' ?>>
                    فعال
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">بروزرسانی</button>
                <a href="/admin/banners" class="btn btn-secondary">انصراف</a>
            </div>
        </form>
    </div>
</div>

<style>
.form-container{background:#fff;padding:30px;border-radius:8px;max-width:800px;margin:0 auto}
.form-group{margin-bottom:20px}
.form-group label{display:block;margin-bottom:8px;font-weight:500}
.form-control{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.form-actions{display:flex;gap:10px;margin-top:30px}
.checkbox-label{display:flex;align-items:center;gap:8px;cursor:pointer}
</style>

<?php include __DIR__ . '/../../partials/admin/footer.php'; ?>
