<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>۴۲۹ - تعداد درخواست بیش از حد | چرتکه</title>
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
</head>
<body>
<div class="error-decoration error-decoration-1"></div>
<div class="error-decoration error-decoration-2"></div>
<div class="error-page">
    <div class="error-box">
        <div class="error-code-num">429</div>
        <span class="material-icons error-icon">speed</span>
        <h1 class="error-title">درخواست بیش از حد!</h1>
        <p class="error-desc">
            تعداد درخواست‌های شما بیش از حد مجاز است.<br>
            <?php if (!empty($retryAfter)): ?>
            لطفاً <?= (int)$retryAfter ?> ثانیه صبر کنید.
            <?php else: ?>
            لطفاً چند دقیقه صبر کرده و دوباره امتحان کنید.
            <?php endif; ?>
        </p>
        <div class="error-actions">
            <button onclick="location.reload()" class="error-btn error-btn-primary">
                <span class="material-icons">refresh</span>
                تلاش مجدد
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
