<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>۴۰۳ - دسترسی غیرمجاز | چرتکه</title>
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
</head>
<body>
<div class="error-decoration error-decoration-1"></div>
<div class="error-decoration error-decoration-2"></div>
<div class="error-page">
    <div class="error-box">
        <div class="error-code-num">403</div>
        <span class="material-icons error-icon">lock</span>
        <h1 class="error-title">دسترسی غیرمجاز</h1>
        <p class="error-desc">
            متأسفانه اجازه دسترسی به این صفحه را نداری.<br>
            اگر فکر می‌کنی این اشتباه است با پشتیبانی تماس بگیر.
        </p>
        <div class="error-actions">
            <a href="<?= url('/') ?>" class="error-btn error-btn-primary">
                <span class="material-icons">home</span>
                صفحه اصلی
            </a>
            <a href="<?= url('/tickets/create') ?>" class="error-btn error-btn-outline">
                <span class="material-icons">support_agent</span>
                تماس با پشتیبانی
            </a>
        </div>
    </div>
</div>
</body>
</html>
