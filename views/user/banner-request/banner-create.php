<?php
$pageTitle = 'درخواست تبلیغ جدید';
$layout = 'user';
ob_start();
?>

<div class="user-content">
    <div class="page-header">
        <h1>درخواست تبلیغ جدید</h1>
        <a href="/banner-request" class="btn btn-secondary">بازگشت</a>
    </div>

    <div class="form-container">
        <form method="POST" action="/banner-request/store" enctype="multipart/form-data">
            
            <div class="info-box">
                <h3>💡 راهنما</h3>
                <ul>
                    <li><strong>بنر استارتاپی:</strong> ویژه کسب‌کارهای نوپا - 7 روز رایگان</li>
                    <li><strong>بنر کاربری:</strong> تبلیغ عمومی - بر اساس جایگاه و مدت</li>
                </ul>
            </div>

            <div class="form-group">
                <label>نوع تبلیغ *</label>
                <select name="banner_type" class="form-control" onchange="updatePricing(this.value)">
                    <option value="user">تبلیغ عادی</option>
                    <option value="startup">تبلیغ استارتاپی (ویژه کسب‌کارهای نوپا)</option>
                </select>
            </div>

            <div class="form-group" id="categoryGroup" style="display:none;">
                <label>دسته‌بندی *</label>
                <select name="category" class="form-control">
                    <option value="startup">استارتاپ / کسب‌کار نوپا</option>
                    <option value="ngo">سازمان غیرانتفاعی (NGO)</option>
                    <option value="educational">آموزشی</option>
                </select>
            </div>

            <div class="form-group">
                <label>عنوان *</label>
                <input type="text" name="title" required class="form-control" placeholder="نام کسب‌کار یا محصول">
            </div>

            <div class="form-group">
                <label>تصویر *</label>
                <input type="file" name="image" accept="image/*" required class="form-control">
                <small>حداکثر: 2MB | فرمت: JPG, PNG, GIF</small>
            </div>

            <div class="form-group">
                <label>لینک (اختیاری)</label>
                <input type="url" name="link" class="form-control" placeholder="https://example.com">
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
                <label>مدت زمان *</label>
                <select name="duration_days" class="form-control" onchange="updatePricing()">
                    <option value="7">7 روز</option>
                    <option value="14">14 روز</option>
                    <option value="30">30 روز</option>
                </select>
            </div>

            <div class="form-group">
                <label>توضیحات (اختیاری)</label>
                <textarea name="alt_text" class="form-control" rows="3" placeholder="توضیح کوتاه درباره کسب‌کار یا محصول"></textarea>
            </div>

            <div class="price-box" id="priceBox">
                <div class="price-label">هزینه:</div>
                <div class="price-amount" id="priceAmount">محاسبه می‌شود...</div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">ارسال درخواست</button>
                <a href="/banner-request" class="btn btn-secondary">انصراف</a>
            </div>
        </form>
    </div>
</div>

<script>
function updatePricing(type) {
    const categoryGroup = document.getElementById('categoryGroup');
    const priceAmount = document.getElementById('priceAmount');
    const durationDays = parseInt(document.querySelector('[name="duration_days"]').value);
    
    if (!type) type = document.querySelector('[name="banner_type"]').value;
    
    categoryGroup.style.display = type === 'startup' ? 'block' : 'none';
    
    if (type === 'startup' && durationDays === 7) {
        priceAmount.textContent = 'رایگان 🎉';
        priceAmount.style.color = '#28a745';
    } else if (type === 'startup') {
        const price = (durationDays - 7) * 500;
        priceAmount.textContent = price.toLocaleString('fa-IR') + ' تومان';
        priceAmount.style.color = '#333';
    } else {
        const price = durationDays * 2000;
        priceAmount.textContent = price.toLocaleString('fa-IR') + ' تومان';
        priceAmount.style.color = '#333';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updatePricing();
});
</script>

<style>
.user-content{max-width:800px;margin:0 auto;padding:30px}
.form-container{background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1)}
.info-box{background:#e7f3ff;border-right:4px solid #2196f3;padding:20px;margin-bottom:30px;border-radius:4px}
.info-box h3{margin:0 0 10px;font-size:16px;color:#1976d2}
.info-box ul{margin:0;padding-right:20px}
.info-box li{margin-bottom:8px}
.form-group{margin-bottom:20px}
.form-group label{display:block;margin-bottom:8px;font-weight:500}
.form-control{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px}
.price-box{background:#f8f9fa;padding:20px;border-radius:8px;margin:20px 0;text-align:center}
.price-label{font-size:14px;color:#666;margin-bottom:5px}
.price-amount{font-size:28px;font-weight:bold;color:#333}
.form-actions{display:flex;gap:10px;margin-top:30px}
</style>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
