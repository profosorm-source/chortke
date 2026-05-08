<?php
/**
 * Email Header Partial
 * در همه email templates include می‌شود
 * $headerColor: رنگ پس‌زمینه (پیش‌فرض gradient بنفش)
 * $headerTitle: عنوان ایمیل
 * $headerSubtitle: زیرعنوان (اختیاری)
 */
$headerColor = $headerColor ?? 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
// FIX B-12: فقط رنگ‌های مجاز از لیست سفید پذیرفته می‌شوند تا از CSS injection جلوگیری شود
$allowedColors = [
    'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
    'linear-gradient(135deg, #2ecc71 0%, #27ae60 100%)',
    'linear-gradient(135deg, #e74c3c 0%, #c0392b 100%)',
    'linear-gradient(135deg, #3498db 0%, #2980b9 100%)',
    'linear-gradient(135deg, #f39c12 0%, #e67e22 100%)',
    '#667eea', '#764ba2', '#2ecc71', '#e74c3c', '#3498db', '#f39c12',
];
if (!in_array($headerColor, $allowedColors, true)) {
    // اگر مقدار در لیست سفید نبود، رنگ پیش‌فرض استفاده می‌شود
    $headerColor = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
}
$headerTitle = $headerTitle ?? setting('site_name', 'چرتکه');
$headerSubtitle = $headerSubtitle ?? '';

$__logo = site_logo('main');
$__siteName = setting('site_name', 'چرتکه');
$__siteUrl = url('/');
?>
        <div class="header" style="background: <?= $headerColor ?>; color: white; padding: 32px 20px; text-align: center;">
            <?php if ($__logo): ?>
                <a href="<?= $__siteUrl ?>" style="display:inline-block; margin-bottom: 12px;">
                    <img src="<?= $__logo ?>" alt="<?= e($__siteName) ?>"
                         style="max-height: 55px; max-width: 180px; object-fit: contain;"
                         onerror="this.style.display='none'">
                </a>
            <?php else: ?>
                <div style="font-size:22px; font-weight:900; letter-spacing:1px; margin-bottom:12px;">
                    <?= e($__siteName) ?>
                </div>
            <?php endif; ?>
            <h1 style="margin: 0; font-size: 22px; font-weight: bold;"><?= e($headerTitle) ?></h1>
            <?php if ($headerSubtitle): ?>
                <p style="margin: 8px 0 0; opacity: .85; font-size: 14px;"><?= e($headerSubtitle) ?></p>
            <?php endif; ?>
        </div>