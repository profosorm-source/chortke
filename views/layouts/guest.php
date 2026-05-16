<?php
/**
 * لایوت مهمان (صفحات عمومی)
 * هوم، قوانین، تماس، راهنما، لاگین، ثبت‌نام
 */
$siteName = setting('site_name') ?? 'چرتکه';
$siteDesc = setting('site_description') ?? 'پلتفرم کسب درآمد آنلاین';
$isLoggedIn = auth();
$siteLogo = site_logo('main') ?? '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($siteDesc) ?>">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://ajax.googleapis.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';">
    <meta name="theme-color" content="#1565c0">
    <title><?= e($title ?? $siteName) ?></title>

    <!-- Favicon (از تنظیمات سیستم) -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>

    <!-- Preconnect -->

    <!-- Material Icons -->

    <!-- Bootstrap 5 RTL -->
    <!-- Material Icons (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/material-icons.css') ?>">
    <!-- Vazirmatn Font (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">

    <!-- Notyf -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">
    <!-- Global site CSS -->
    <link rel="stylesheet" href="<?= asset('assets/css/chortke.css') ?>">
    <!-- Pages (static pages, error pages) CSS -->
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">

    <style>
        /* ═══════════════════════════════════
           ریست و پایه
           ═══════════════════════════════════ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #4fc3f7;
            --primary-dark: #29b6f6;
            --primary-darker: #0288d1;
            --primary-light: rgba(79,195,247,0.1);
            --primary-glow: rgba(79,195,247,0.3);
            --success: #4caf50;
            --danger: #f44336;
            --warning: #ffa726;
            --info: #2196f3;
            --dark: #1a1a2e;
            --dark-2: #333;
            --gray: #777;
            --gray-light: #aaa;
            --light: #f5f7fa;
            --lighter: #fafbfc;
            --white: #ffffff;
            --border: #e8ecf1;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow: 0 2px 12px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 35px rgba(0,0,0,0.12);
            --shadow-xl: 0 15px 50px rgba(0,0,0,0.15);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
            --radius-full: 50px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --navbar-height: 72px;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: var(--font-family);
            background: var(--white);
            color: var(--dark-2);
            line-height: 1.6;
            padding-top: var(--navbar-height);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        a { text-decoration: none; }
        img { max-width: 100%; height: auto; }

        /* ═══════════════════════════════════
           نوار ناوبری
           ═══════════════════════════════════ */
        .guest-navbar {
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1050;
            height: var(--navbar-height);
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
        }

        .guest-navbar.scrolled {
            height: 62px;
            background: rgba(255,255,255,0.97);
            box-shadow: 0 1px 20px rgba(0,0,0,0.08);
        }

        .navbar-inner {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 100%;
        }

        /* لوگو */
        .navbar-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 24px;
            font-weight: 800;
            position: relative;
        }

        .navbar-logo img {
            height: 38px;
            width: auto;
            transition: var(--transition);
        }

        .navbar-logo-text {
            background: linear-gradient(135deg, var(--primary), var(--primary-darker));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }

        .navbar-logo:hover img {
            transform: scale(1.05);
        }

        /* لینک‌ها */
        .navbar-links {
            display: flex;
            align-items: center;
            gap: 4px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .navbar-links li a {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--dark-2);
            font-size: 13.5px;
            font-weight: 500;
            padding: 9px 16px;
            border-radius: var(--radius-sm);
            transition: var(--transition);
            position: relative;
        }

        .navbar-links li a .material-icons {
            font-size: 18px;
            opacity: 0.7;
        }

        .navbar-links li a:hover {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .navbar-links li a:hover .material-icons {
            opacity: 1;
        }

        /* دکمه‌های ناوبری */
        .btn-nav-outline {
            border: 2px solid var(--primary) !important;
            color: var(--primary) !important;
            padding: 7px 22px !important;
            border-radius: var(--radius-full) !important;
            font-weight: 600 !important;
            transition: var(--transition) !important;
        }

        .btn-nav-outline:hover {
            background: var(--primary) !important;
            color: var(--white) !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 15px var(--primary-glow);
        }

        .btn-nav-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark)) !important;
            color: var(--white) !important;
            padding: 9px 24px !important;
            border-radius: var(--radius-full) !important;
            font-weight: 600 !important;
            box-shadow: 0 2px 10px var(--primary-glow);
            transition: var(--transition) !important;
        }

        .btn-nav-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px var(--primary-glow);
        }

        /* منوی موبایل */
        .navbar-toggle {
            display: none;
            background: none;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            padding: 6px 8px;
            transition: var(--transition);
        }

        .navbar-toggle:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .navbar-toggle .material-icons {
            font-size: 24px;
            color: var(--dark-2);
            display: block;
        }

        /* موبایل */
        @media (max-width: 868px) {
            .navbar-toggle {
                display: flex;
                align-items: center;
            }

            .navbar-links {
                display: none;
                position: absolute;
                top: 100%;
                right: 0;
                left: 0;
                background: var(--white);
                flex-direction: column;
                padding: 12px 16px;
                box-shadow: var(--shadow-lg);
                border-bottom-left-radius: var(--radius-lg);
                border-bottom-right-radius: var(--radius-lg);
                gap: 4px;
                animation: slideDown 0.3s ease;
            }

            .navbar-links.active {
                display: flex;
            }

            .navbar-links li {
                width: 100%;
            }

            .navbar-links li a {
                width: 100%;
                justify-content: center;
                padding: 12px;
                border-radius: var(--radius-sm);
            }

            @keyframes slideDown {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
        }

        /* ═══════════════════════════════════
           فوتر
           ═══════════════════════════════════ */
        .guest-footer {
            background: var(--dark);
            color: rgba(255,255,255,0.7);
            padding: 60px 0 0;
            position: relative;
            overflow: hidden;
        }

        .guest-footer::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            left: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark), var(--info), var(--primary));
            background-size: 300% 100%;
            animation: gradientMove 4s ease infinite;
        }

        @keyframes gradientMove {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .footer-inner {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1fr;
            gap: 40px;
            padding-bottom: 40px;
        }

        .footer-col h4 {
            font-size: 15px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 20px;
            position: relative;
            padding-bottom: 12px;
        }

        .footer-col h4::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 35px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), transparent);
            border-radius: 2px;
        }

        .footer-about-text {
            font-size: 13px;
            line-height: 1.9;
            color: rgba(255,255,255,0.6);
            margin-bottom: 20px;
        }

        .footer-social {
            display: flex;
            gap: 10px;
        }

        .footer-social a {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.7);
            transition: var(--transition);
        }

        .footer-social a:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
            transform: translateY(-3px);
        }

        .footer-social a .material-icons {
            font-size: 18px;
        }

        .footer-col ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-col ul li {
            margin-bottom: 10px;
        }

        .footer-col ul li a {
            color: rgba(255,255,255,0.6);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            padding: 3px 0;
        }

        .footer-col ul li a .material-icons {
            font-size: 16px;
            opacity: 0.5;
            transition: var(--transition);
        }

        .footer-col ul li a:hover {
            color: var(--primary);
            padding-right: 6px;
        }

        .footer-col ul li a:hover .material-icons {
            opacity: 1;
            color: var(--primary);
        }

        /* درگاه‌های پرداخت */
        .footer-gateways {
            border-top: 1px solid rgba(255,255,255,0.08);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 25px 0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .footer-gateways-label {
            font-size: 12px;
            color: rgba(255,255,255,0.4);
            margin-left: 8px;
        }

        .footer-gateway-chip {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            padding: 6px 14px;
            border-radius: var(--radius-full);
            font-size: 11px;
            color: rgba(255,255,255,0.6);
            display: flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
        }

        .footer-gateway-chip:hover {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.2);
            color: rgba(255,255,255,0.9);
        }

        .footer-gateway-chip .material-icons {
            font-size: 14px;
        }

        /* کپی‌رایت */
        .footer-bottom {
            text-align: center;
            padding: 20px 0;
            font-size: 12px;
            color: rgba(255,255,255,0.35);
        }

        .footer-bottom a {
            color: var(--primary);
            transition: var(--transition);
        }

        .footer-bottom a:hover {
            color: var(--primary-dark);
        }

        /* ریسپانسیو فوتر */
        @media (max-width: 868px) {
            .footer-grid {
                grid-template-columns: 1fr 1fr;
                gap: 30px;
            }
        }

        @media (max-width: 480px) {
            .footer-grid {
                grid-template-columns: 1fr;
                gap: 25px;
            }
        }

        /* ═══════════════════════════════════
           اسکرول به بالا
           ═══════════════════════════════════ */
        .scroll-top-btn {
            position: fixed;
            bottom: 30px;
            left: 30px;
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-md);
            z-index: 999;
            transition: var(--transition);
        }

        .scroll-top-btn.visible {
            display: flex;
            animation: fadeInUp 0.3s ease;
        }

        .scroll-top-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ═══════════════════════════════════
           Loading
           ═══════════════════════════════════ */
        .page-loader {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--white);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 15px;
            transition: opacity 0.5s ease;
        }

        .page-loader.hide {
            opacity: 0;
            pointer-events: none;
        }

        .loader-spinner {
            width: 45px;
            height: 45px;
            border: 4px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        .loader-text {
            font-size: 13px;
            color: var(--gray);
            font-weight: 500;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>

    <!-- CSS اضافی -->
    <?= $extra_css ?? '' ?>
</head>
<body onload="document.getElementById('pageLoader').classList.add('hide')">

<!-- لودینگ -->
<div class="page-loader" id="pageLoader">
    <div class="loader-spinner"></div>
    <div class="loader-text"><?= e($siteName) ?></div>
</div>

<!-- ═══ NAVBAR ═══ -->
<nav class="guest-navbar" id="guestNavbar">
    <div class="navbar-inner">
        <a href="<?= url('/') ?>" class="navbar-logo">
            <?php if ($siteLogo): ?>
                <img src="<?= url($siteLogo) ?>" alt="<?= e($siteName) ?>">
            <?php endif; ?>
            <span class="navbar-logo-text"><?= e($siteName) ?></span>
        </a>

        <button class="navbar-toggle" id="navbarToggle" aria-label="منو">
            <span class="material-icons" id="navbarToggleIcon">menu</span>
        </button>

        <ul class="navbar-links" id="navbarLinks">
            <li><a href="<?= url('/') ?>"><span class="material-icons">home</span> خانه</a></li>
            <li><a href="<?= url('/help') ?>"><span class="material-icons">menu_book</span> راهنما</a></li>
            <li><a href="<?= url('/terms') ?>"><span class="material-icons">gavel</span> قوانین</a></li>
            <li><a href="<?= url('/contact') ?>"><span class="material-icons">mail</span> تماس</a></li>
            <?php if ($isLoggedIn): ?>
                <li><a href="<?= url('/dashboard') ?>" class="btn-nav-primary">
                    <span class="material-icons">dashboard</span> داشبورد
                </a></li>
            <?php else: ?>
                <li><a href="<?= url('/login') ?>" class="btn-nav-outline">
                    <span class="material-icons">login</span> ورود
                </a></li>
                <li><a href="<?= url('/register') ?>" class="btn-nav-primary">
                    <span class="material-icons">person_add</span> ثبت‌نام رایگان
                </a></li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<!-- ═══ محتوا ═══ -->
<main>
    <?= $content ?? '' ?>
</main>


<!-- دکمه اسکرول به بالا -->
<button class="scroll-top-btn" id="scrollTopBtn" aria-label="بازگشت به بالا">
    <span class="material-icons">keyboard_arrow_up</span>
</button>

<!-- JS -->
<script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
<script>
    // BASE_URL
    window.BASE_URL = '<?= rtrim(url('/'), '/') ?>';

    // لودینگ - hide کردن
    function hidePageLoader() {
        var loader = document.getElementById('pageLoader');
        if (loader) {
            loader.classList.add('hide');
            setTimeout(function() { 
                try { 
                    if (loader && loader.parentNode) {
                        loader.parentNode.removeChild(loader);
                    }
                } catch(e) {}
            }, 500);
        }
    }

    // Immediately call
    hidePageLoader();

    // منوی موبایل
    (function() {
        var toggle = document.getElementById('navbarToggle');
        var links = document.getElementById('navbarLinks');
        var icon = document.getElementById('navbarToggleIcon');
        if (toggle && links) {
            toggle.addEventListener('click', function() {
                var isActive = links.classList.toggle('active');
                if (icon) icon.textContent = isActive ? 'close' : 'menu';
            });
            // بستن با کلیک بیرون
            document.addEventListener('click', function(e) {
                if (!toggle.contains(e.target) && !links.contains(e.target)) {
                    links.classList.remove('active');
                    if (icon) icon.textContent = 'menu';
                }
            });
        }
    })();

    // navbar scroll
    (function() {
        var navbar = document.getElementById('guestNavbar');
        if (navbar) {
            window.addEventListener('scroll', function() {
                navbar.classList.toggle('scrolled', window.scrollY > 60);
            });
        }
    })();

    // دکمه بالا
    (function() {
        var btn = document.getElementById('scrollTopBtn');
        if (btn) {
            window.addEventListener('scroll', function() {
                btn.classList.toggle('visible', window.scrollY > 400);
            });
            btn.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    })();
</script>
<?php


include view_path('layouts.footer');

?>
<!-- JS اضافی -->
<?= $extra_js ?? '' ?>
<?= captcha_refresh_script() ?>
</body>
</html>