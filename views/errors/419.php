<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>۴۱۹ - نشست منقضی | چرتکه</title>
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
</head>
<body>
<div class="error-decoration error-decoration-1"></div>
<div class="error-decoration error-decoration-2"></div>
<div class="error-page">
    <div class="error-box">
        <div class="error-code-num">419</div>
        <span class="material-icons error-icon">timer_off</span>
        <h1 class="error-title">نشست منقضی شده</h1>
        <p class="error-desc">
            توکن امنیتی صفحه منقضی شده است.<br>
            لطفاً صفحه را رفرش کرده و دوباره امتحان کن.
        </p>
        <div class="error-actions">
            <button onclick="location.reload()" class="error-btn error-btn-primary">
                <span class="material-icons">refresh</span>
                رفرش صفحه
            </button>
            <a href="<?= url('/') ?>" class="error-btn error-btn-outline">
                <span class="material-icons">home</span>
                صفحه اصلی
            </a>
        </div>
    </div>
</div>
</body>
</html>
