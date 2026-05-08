<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>۵۰۰ - خطای سرور | چرتکه</title>
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
</head>
<body>
<div class="error-decoration error-decoration-1"></div>
<div class="error-decoration error-decoration-2"></div>
<div class="error-page">
    <div class="error-box">
        <div class="error-code-num">500</div>
        <span class="material-icons error-icon">error_outline</span>
        <h1 class="error-title">خطای سرور!</h1>
        <p class="error-desc">
            یک مشکل داخلی در سرور رخ داده است.<br>
            تیم فنی ما در حال بررسی است. لطفاً چند دقیقه بعد دوباره امتحان کن.
        </p>
        <div class="error-actions">
            <a href="<?= url('/') ?>" class="error-btn error-btn-primary">
                <span class="material-icons">home</span>
                صفحه اصلی
            </a>
            <button onclick="location.reload()" class="error-btn error-btn-outline">
                <span class="material-icons">refresh</span>
                تلاش مجدد
            </button>
        </div>
    </div>
</div>
</body>
</html>
