<?php
// ─── User Panel Layout ─────────────────────────────────────
// این فایل لایوت اصلی پنل کاربری است
// navbar, sidebar و footer از فایل‌های جداگانه در partials/user/ بارگذاری می‌شوند

$isLoggedIn = $isLoggedIn ?? false;
$currentUser = $currentUser ?? null;

// Flash messages
$flashSuccess = $flashSuccess ?? null;
$flashError   = $flashError   ?? null;
$flashWarning = $flashWarning ?? null;

// اطلاعات کاربر
$fullName   = 'کاربر';
$firstLetter = 'ک';
if ($currentUser && !empty($currentUser->full_name)) {
    $fullName    = (string)$currentUser->full_name;
    $firstLetter = mb_substr(trim($fullName), 0, 1, 'UTF-8');
}

$tier      = $tier ?? ($currentUser->tier ?? 'SILVER');
$kycStatus = $kycStatus ?? ($currentUser->kyc_status ?? 'pending');
$kycMap    = [
    'verified'      => 'تایید شده',
    'pending'       => 'در انتظار',
    'review_under'  => 'در بررسی',
    'under_review'  => 'در بررسی',
    'rejected'      => 'رد شده',
    'expired'       => 'منقضی',
];
$kycLabel = $kycMap[$kycStatus] ?? 'ناقص';

$notifCount      = $notifCount ?? 0;
$topNotifications = $topNotifications ?? [];
$openTicketCount  = $openTicketCount ?? 0;

$avatarFile = ($currentUser && !empty($currentUser->avatar)) ? $currentUser->avatar : 'default-avatar.png';
$avatarUrl  = asset('uploads/avatars/' . $avatarFile);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'پنل کاربری') ?> | <?= e(setting('site_name', 'چرتکه')) ?></title>
    <meta name="csrf-token" content="<?= csrf_token() ?>">

    <!-- Favicon (از تنظیمات سیستم) -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>

    <!-- Material Icons (local) -->
    
 <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <!-- Vazirmatn Font (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/panel.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/sweetalert2/sweetalert2.min.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/material-icons.css') ?>">
    <?= $styles ?? '' ?>
</head>
<body>

<?php include view_path('partials.user.navbar'); ?>
<?php include view_path('partials.user.sidebar'); ?>

<!-- Main Content -->
<div class="main-content">
    <div class="content-wrapper">

        <!-- KYC Warning -->
        <?php if ($isLoggedIn && (!isset($currentUser->kyc_verified) || $currentUser->kyc_verified != 1)): ?>
            <div class="alert alert-warning d-flex align-items-center mb-4">
                <span class="material-icons me-2">warning</span>
                <span>
                    لطفاً برای استفاده کامل از امکانات،
                    <a href="<?= url('/user/kyc') ?>" class="alert-link fw-bold">احراز هویت</a>
                    خود را تکمیل کنید.
                </span>
            </div>
        <?php endif; ?>

        <!-- Email Verification Warning — یک‌بار نشان داده می‌شود -->
        <?php
        $showEmailNotice = false;
        if ($isLoggedIn && $currentUser && empty($currentUser->email_verified_at)) {
            $session = app()->session;
            if ($session->get('show_email_verify_notice')) {
                $showEmailNotice = true;
                $session->remove('show_email_verify_notice');
            }
        }
        ?>
        <?php if ($showEmailNotice): ?>
            <div class="alert alert-info d-flex align-items-center justify-content-between mb-4">
                <div class="d-flex align-items-center">
                    <span class="material-icons me-2">mark_email_unread</span>
                    <span>
                        ایمیل شما هنوز تأیید نشده است.
                        <a href="<?= url('/profile#verify-email') ?>" class="alert-link fw-bold">از تنظیمات پروفایل</a>
                        می‌توانید آن را تأیید کنید.
                    </span>
                </div>
                <button type="button" class="btn-close ms-3" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?= $content ?? '' ?>
    </div>

    <?php include view_path('partials.user.footer'); ?>
</div>

<!-- دکمه شب/روز ثابت گوشه چپ پایین -->
<button class="theme-fab" id="themeToggleBtn" onclick="togglePanelTheme()" title="تغییر تم">
  <span class="material-icons" id="themeIcon">dark_mode</span>
</button>

<script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script src="<?= asset('assets/js/swal-init.js') ?>?v=<?= time() ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
<script>
window.csrfToken = "<?= csrf_token() ?>";

// ── Theme (Dark/Light) ──
function togglePanelTheme(){
  var html = document.documentElement;
  var dark = html.getAttribute('data-theme') === 'dark';
  var nt = dark ? 'light' : 'dark';
  html.setAttribute('data-theme', nt);
  localStorage.setItem('panel_theme', nt);
  var ic = document.getElementById('themeIcon');
  if(ic) ic.textContent = nt === 'dark' ? 'light_mode' : 'dark_mode';
}
(function(){
  var t = localStorage.getItem('panel_theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
  var ic = document.getElementById('themeIcon');
  if(ic) ic.textContent = t === 'dark' ? 'light_mode' : 'dark_mode';
})();

// ── Notyf flash messages ──
const notyf = new Notyf({duration:5000,position:{x:'left',y:'top'},dismissible:true});
<?php if ($flashSuccess): ?>notyf.success(<?= json_encode((string)$flashSuccess, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);<?php endif; ?>
<?php if ($flashError): ?>notyf.error(<?= json_encode((string)$flashError, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);<?php endif; ?>
<?php if (!empty($flashWarning)): ?>notyf.open({type:'warning',message:<?= json_encode((string)$flashWarning, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>});<?php endif; ?>

document.addEventListener('DOMContentLoaded', function () {

  // ── Sidebar mobile toggle ──
  const sidebarToggle  = document.getElementById('sidebarToggle');
  const mainSidebar    = document.getElementById('mainSidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');

  if (sidebarToggle && mainSidebar) {
    sidebarToggle.addEventListener('click', function(e){
      e.stopPropagation();
      mainSidebar.classList.toggle('is-open');
      if(sidebarOverlay) sidebarOverlay.classList.toggle('is-open');
    });
  }
  if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', function(){
      mainSidebar.classList.remove('is-open');
      sidebarOverlay.classList.remove('is-open');
    });
  }

  // ── Submenu accordion ──
  document.querySelectorAll('[data-submenu-toggle]').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      var li = this.closest('li.has-submenu');
      if (!li) return;
      // close others
      document.querySelectorAll('li.has-submenu.open').forEach(function(other){
        if(other !== li) other.classList.remove('open');
      });
      li.classList.toggle('open');
    });
  });

  // ── Dropdown menus (notifications, settings) ──
  document.querySelectorAll('[data-dd-toggle]').forEach(function(trigger){
    trigger.addEventListener('click', function(e){
      e.stopPropagation();
      var key  = this.dataset.ddToggle;
      var menu = document.querySelector('[data-dd-menu="' + key + '"]');
      if (!menu) return;
      var rect = this.getBoundingClientRect();
      menu.style.top   = (rect.bottom + 6) + 'px';
      menu.style.right = (window.innerWidth - rect.right) + 'px';
      menu.style.left  = 'auto';
      // close others
      document.querySelectorAll('[data-dd-menu].is-open').forEach(function(m){
        if (m !== menu) m.classList.remove('is-open');
      });
      menu.classList.toggle('is-open');
    });
  });

  // click outside closes dropdowns
  document.addEventListener('click', function(){
    document.querySelectorAll('[data-dd-menu].is-open').forEach(function(m){
      m.classList.remove('is-open');
    });
  });

});
</script>

<?= $scripts ?? '' ?>
<?= captcha_refresh_script() ?>
</body>
</html>