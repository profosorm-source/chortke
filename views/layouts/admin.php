<?php
// ─── Admin Panel Layout ─────────────────────────────────────
// navbar و sidebar از فایل‌های جداگانه در partials/admin/ بارگذاری می‌شوند

$currentUser = $currentUser ?? null;
$flashSuccess = $flashSuccess ?? null;
$flashError = $flashError ?? null;

$fullName    = 'مدیر';
$firstLetter = 'م';
if ($currentUser && isset($currentUser->full_name) && !empty($currentUser->full_name)) {
    $fullName    = $currentUser->full_name;
    $firstLetter = mb_substr($fullName, 0, 1, 'UTF-8');
}
$roleNames = [
    'admin'   => 'مدیر کل',
    'support' => 'پشتیبان',
    'user'    => 'کاربر',
];
$userRole = isset($currentUser->role) ? ($roleNames[$currentUser->role] ?? 'کاربر') : 'مدیر';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'پنل مدیریت') ?> | <?= e(setting('site_name', 'چرتکه')) ?></title>
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://ajax.googleapis.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';">

    <!-- Favicon (از تنظیمات سیستم) -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>

    <!-- Material Icons (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/material-icons.css') ?>">
    <!-- Vazirmatn Font (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/chortke.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/sweetalert2/sweetalert2.min.css') ?>">

    <?= $styles ?? '' ?>
</head>
<body>

<?php include view_path('partials.admin.sidebar'); ?>

<!-- Main Content -->
<div class="main-content">

    <?php include view_path('partials.admin.navbar'); ?>

    <!-- Content -->
    <div class="content-wrapper">
        <div id="toast-container"></div>

        <?= $content ?? '' ?>
    </div>
</div>

<script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>
<script src="<?= asset('assets/js/swal-init.js') ?>?v=<?= time() ?>"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>

<script>
window.csrfToken = "<?= csrf_token() ?>";

const notyf = new Notyf({
    duration: 5000,
    position: { x: 'left', y: 'top' },
    dismissible: true
});

<?php if ($flashSuccess): ?>
    notyf.success(<?= json_encode((string)$flashSuccess, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php endif; ?>
<?php if ($flashError): ?>
    notyf.error(<?= json_encode((string)$flashError, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php endif; ?>

// Sidebar Collapse Toggle (Admin)
document.addEventListener('DOMContentLoaded', function () {
    // ─── Sub-menu toggle ───────────────────────────────────────────
    window.toggleAdminSub = function(el) {
        const sub = el.nextElementSibling;
        if (!sub || !sub.classList.contains('nav-submenu')) return;
        const isOpen = sub.classList.contains('open');
        // Close all subs
        document.querySelectorAll('.nav-submenu.open').forEach(s => {
            s.classList.remove('open');
            s.style.maxHeight = '';
            const btn = s.previousElementSibling;
            if (btn) btn.classList.remove('open');
        });
        // Toggle current
        if (!isOpen) {
            sub.classList.add('open');
            sub.style.maxHeight = sub.scrollHeight + 'px';
            el.classList.add('open');
        }
    };

    // ─── Menu search ─────────────────────────────────────────────
    const searchInput = document.getElementById('sidebarMenuSearch');
    const nav = document.getElementById('sidebarNav');
    if (searchInput && nav) {
        searchInput.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            const allItems = nav.querySelectorAll('.nav-item, .nav-sub-item');
            const sections = nav.querySelectorAll('.nav-section');

            if (!q) {
                allItems.forEach(i => i.style.display = '');
                sections.forEach(s => s.style.display = '');
                nav.querySelectorAll('.nav-submenu').forEach(m => {
                    if (!m.classList.contains('open')) m.style.maxHeight = '';
                });
                return;
            }

            sections.forEach(section => {
                let hasVisible = false;
                section.querySelectorAll('.nav-item, .nav-sub-item').forEach(item => {
                    const label = item.querySelector('.nav-label, .nav-sub-dot')?.nextSibling?.textContent?.toLowerCase() || item.textContent.toLowerCase();
                    const match = label.includes(q);
                    item.style.display = match ? '' : 'none';
                    if (match) hasVisible = true;
                });
                // Show all submenus when searching
                section.querySelectorAll('.nav-submenu').forEach(m => m.style.maxHeight = hasVisible ? '500px' : '');
                section.style.display = hasVisible ? '' : 'none';
            });
        });
    }

    // Init: open currently active sub-menus
    document.querySelectorAll('.nav-submenu').forEach(sub => {
        if (sub.classList.contains('open')) {
            sub.style.maxHeight = sub.scrollHeight + 'px';
        }
    });
});
</script>

<?= $scripts ?? '' ?>
<?= captcha_refresh_script() ?>
</body>
</html>