<?php
$pageTitle = 'افزودن بنر';
include __DIR__ . '/../../partials/admin/header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1>افزودن بنر جدید</h1>
        <a href="/admin/banners" class="btn btn-secondary">بازگشت</a>
    </div>

    <div class="form-container">
        <form method="POST" action="/admin/banners/store" enctype="multipart/form-data">
            <div class="form-group">
                <label>عنوان *</label>
                <input type="text" name="title" required class="form-control">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>نوع بنر *</label>
                    <select name="banner_type" class="form-control" onchange="toggleCategory(this.value)">
                        <option value="system">سیستمی</option>
                        <option value="startup">استارتاپی</option>
                        <option value="user">کاربری</option>
                        <option value="promo">تبلیغاتی</option>
                    </select>
                </div>

                <div class="form-group" id="categoryGroup" style="display:none;">
                    <label>دسته‌بندی</label>
                    <select name="category" class="form-control">
                        <option value="">انتخاب کنید</option>
                        <option value="startup">استارتاپ</option>
                        <option value="ngo">NGO</option>
                        <option value="educational">آموزشی</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>جایگاه *</label>
                <select name="placement" required class="form-control">
                    <?php foreach ($placements as $p): ?>
                        <option value="<?= $p->slug ?>"><?= e($p->title) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>تصویر</label>
                <input type="file" name="image" accept="image/*" class="form-control">
                <small>فرمت: JPG, PNG, GIF | حداکثر: 2MB</small>
            </div>

            <div class="form-group">
                <label>لینک</label>
                <input type="url" name="link" class="form-control" placeholder="https://example.com">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ شروع</label>
                    <input type="datetime-local" name="start_date" class="form-control">
                </div>

                <div class="form-group">
                    <label>تاریخ پایان</label>
                    <input type="datetime-local" name="end_date" class="form-control">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>ترتیب نمایش</label>
                    <input type="number" name="sort_order" value="0" class="form-control">
                </div>

                <div class="form-group">
                    <label>باز شدن در</label>
                    <select name="target" class="form-control">
                        <option value="_blank">پنجره جدید</option>
                        <option value="_self">همین پنجره</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>متن جایگزین (alt)</label>
                <input type="text" name="alt_text" class="form-control">
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1" checked>
                    فعال
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">ذخیره</button>
                <a href="/admin/banners" class="btn btn-secondary">انصراف</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleCategory(type) {
    document.getElementById('categoryGroup').style.display = 
        (type === 'startup' || type === 'user') ? 'block' : 'none';
}
</script>

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
