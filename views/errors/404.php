<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>۴۰۴ - صفحه یافت نشد | چرتکه</title>
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
</head>
<body>
<div class="error-decoration error-decoration-1"></div>
<div class="error-decoration error-decoration-2"></div>

<div class="error-page">
    <div class="error-box">
        <div class="error-code-num">404</div>
        <span class="material-icons error-icon">search_off</span>
        <h1 class="error-title">صفحه یافت نشد!</h1>
        <p class="error-desc">
            صفحه‌ای که دنبال آن می‌گردی وجود ندارد یا حذف شده است.<br>
            شاید آدرس را اشتباه تایپ کرده‌ای؟
        </p>
        <div class="error-actions">
            <a href="<?= url('/') ?>" class="error-btn error-btn-primary">
                <span class="material-icons">home</span>
                صفحه اصلی
            </a>
            <a href="javascript:history.back()" class="error-btn error-btn-outline">
                <span class="material-icons">arrow_forward</span>
                بازگشت
            </a>
        </div>
    </div>
</div>
</body>
</html>
