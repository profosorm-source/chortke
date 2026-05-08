<?php
// ─── Admin Sidebar Partial v2 ──────────────────────────────────
// طراحی حرفه‌ای مبتنی بر نمونه admin-panel.html
$uri   = $_SERVER['REQUEST_URI'] ?? '/';
$ac    = fn(string $p) => str_contains($uri, $p) ? 'active' : '';
$openIf = function(array $paths) use ($uri): string {
    foreach ($paths as $p) {
        if (str_contains($uri, $p)) return 'open';
    }
    return '';
};
?>
<!-- ══════════════════════════════════════════
     ADMIN SIDEBAR
     ══════════════════════════════════════════ -->
<aside class="sidebar" id="adminSidebar">

    <!-- Logo -->
    <div class="sidebar-logo">
        <?php $__adminLogo = site_logo('main'); ?>
        <?php if ($__adminLogo): ?>
            <img src="<?= e($__adminLogo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>" style="max-height:40px;max-width:140px;object-fit:contain;">
        <?php else: ?>
            <div class="sidebar-logo-icon">چ</div>
        <?php endif; ?>
        <div class="sidebar-logo-text">
            <h2><?= e(setting('site_name', 'چورتکه')) ?></h2>
            <span>پنل مدیریت سیستم</span>
        </div>
    </div>

    <!-- Admin Info -->
    <div class="sidebar-admin-info">
        <div class="admin-avatar"><?= strtoupper($firstLetter ?? 'م') ?></div>
        <div class="admin-info-text">
            <strong><?= e($fullName ?? 'مدیر') ?></strong>
            <small>● آنلاین</small>
        </div>
        <?php
        // نمایش تعداد موارد نیاز به بررسی
        try {
            $db = \Core\Database::getInstance();
            $r1 = $db->selectOne("SELECT COUNT(*) as c FROM withdrawals WHERE status='pending'");
            $r2 = $db->selectOne("SELECT COUNT(*) as c FROM kyc_verifications WHERE status='pending'");
            $urgent = (int)($r1->c ?? $r1['c'] ?? 0) + (int)($r2->c ?? $r2['c'] ?? 0);
            if ($urgent > 0): ?>
        <div class="admin-info-badge"><?= fa_number($urgent) ?> مورد</div>
        <?php endif;
        } catch(\Exception $e) {}
        ?>
    </div>

    <!-- Search -->
    <div class="sidebar-search">
        <span class="material-icons sidebar-search-icon">search</span>
        <input type="text" id="sidebarMenuSearch" placeholder="جستجو در منو...">
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav" id="sidebarNav">

        <!-- داشبورد -->
        <div class="nav-section">
            <a class="nav-item <?= e($ac('/admin/dashboard')) ?>" href="<?= url('/admin/dashboard') ?>">
                <span class="material-icons nav-icon">dashboard</span>
                <span class="nav-label">داشبورد</span>
            </a>
        </div>

        <!-- ─── کاربران ─── -->
        <div class="nav-section">
            <div class="nav-section-label">کاربران</div>

            <div class="nav-item has-sub <?= e($openIf(['/admin/users', '/admin/roles', '/admin/levels', '/admin/kyc'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">group</span>
                <span class="nav-label">مدیریت کاربران</span>
                <span class="material-icons nav-arrow">chevron_left</span>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/users', '/admin/roles', '/admin/levels', '/admin/kyc'])) ?>">
                <a class="nav-sub-item <?= e($ac('/admin/users')) ?>" href="<?= url('/admin/users') ?>">
                    <span class="nav-sub-dot"></span>همه کاربران
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/roles')) ?>" href="<?= url('/admin/roles') ?>">
                    <span class="nav-sub-dot"></span>نقش‌ها و مجوزها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/levels')) ?>" href="<?= url('/admin/levels') ?>">
                    <span class="nav-sub-dot"></span>سطح‌بندی کاربران
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/referral')) ?>" href="<?= url('/admin/referral') ?>">
                    <span class="nav-sub-dot"></span>سیستم معرفی
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/social-accounts')) ?>" href="<?= url('/admin/social-accounts') ?>">
                    <span class="nav-sub-dot"></span>حساب‌های اجتماعی
                </a>
            </div>

            <a class="nav-item <?= e($ac('/admin/kyc')) ?>" href="<?= url('/admin/kyc') ?>">
                <span class="material-icons nav-icon">how_to_reg</span>
                <span class="nav-label">بررسی KYC</span>
                <?php
                try {
                    $kycPending = \Core\Database::getInstance()->selectOne("SELECT COUNT(*) as c FROM kyc_verifications WHERE status='pending'")->c ?? 0;
                    if ($kycPending > 0): ?>
                    <span class="nav-badge badge-orange"><?= fa_number($kycPending) ?></span>
                    <?php endif;
                } catch(\Exception $e) {}
                ?>
            </a>

            <div class="nav-item has-sub <?= e($openIf(['/admin/account-deletion'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">person_remove</span>
                <span class="nav-label">حذف حساب کاربری</span>
                <span class="material-icons nav-arrow">chevron_left</span>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/account-deletion'])) ?>">
                <a class="nav-sub-item <?= e($ac('/admin/account-deletion/pending')) ?>" href="<?= url('/admin/account-deletion/pending') ?>">
                    <span class="nav-sub-dot"></span>درخواست‌های در انتظار
                    <?php
                    try {
                        $pendingDeletions = \Core\Database::getInstance()->selectOne("SELECT COUNT(*) as c FROM account_deletion_logs WHERE status='requested'")->c ?? 0;
                        if ($pendingDeletions > 0): ?>
                        <span class="nav-badge badge-orange" style="margin-right:auto;"><?= fa_number($pendingDeletions) ?></span>
                        <?php endif;
                    } catch(\Exception $e) {}
                    ?>
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/account-deletion/history')) ?>" href="<?= url('/admin/account-deletion/history') ?>">
                    <span class="nav-sub-dot"></span>سابقه حذف‌شدگی‌ها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/account-deletion/stats')) ?>" href="<?= url('/admin/account-deletion/stats') ?>">
                    <span class="nav-sub-dot"></span>آمار
                </a>
            </div>
        </div>

        <!-- ─── مالی ─── -->
        <div class="nav-section">
            <div class="nav-section-label">مالی و تراکنش‌ها</div>

            <div class="nav-item has-sub <?= e($openIf(['/admin/transactions', '/admin/manual-deposits', '/admin/crypto-deposits'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">account_balance_wallet</span>
                <span class="nav-label">کیف پول و تراکنش‌ها</span>
                <span class="material-icons nav-arrow">chevron_left</span>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/transactions', '/admin/manual-deposits', '/admin/crypto-deposits'])) ?>">
                <a class="nav-sub-item <?= e($ac('/admin/transactions')) ?>" href="<?= url('/admin/transactions') ?>">
                    <span class="nav-sub-dot"></span>همه تراکنش‌ها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/manual-deposits')) ?>" href="<?= url('/admin/manual-deposits') ?>">
                    <span class="nav-sub-dot"></span>واریزهای دستی
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/crypto-deposits')) ?>" href="<?= url('/admin/crypto-deposits') ?>">
                    <span class="nav-sub-dot"></span>واریزهای کریپتو
                </a>
            </div>

            <a class="nav-item <?= e($ac('/admin/withdrawals')) ?>" href="<?= url('/admin/withdrawals') ?>">
                <span class="material-icons nav-icon">payments</span>
                <span class="nav-label">درخواست برداشت</span>
                <?php
                try {
                    $wPending = \Core\Database::getInstance()->selectOne("SELECT COUNT(*) as c FROM withdrawals WHERE status='pending'")->c ?? 0;
                    if ($wPending > 0): ?>
                    <span class="nav-badge badge-orange"><?= fa_number($wPending) ?></span>
                    <?php endif;
                } catch(\Exception $e) {}
                ?>
            </a>

            <a class="nav-item <?= e($ac('/admin/bank-cards')) ?>" href="<?= url('/admin/bank-cards') ?>">
                <span class="material-icons nav-icon">credit_card</span>
                <span class="nav-label">کارت‌های بانکی</span>
            </a>
        </div>

        <!-- ─── سرمایه‌گذاری ─── -->
        <div class="nav-section">
            <div class="nav-section-label">سرمایه‌گذاری</div>

            <div class="nav-item has-sub <?= e($openIf(['/admin/investment'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">trending_up</span>
                <span class="nav-label">سرمایه‌گذاری</span>
                <span class="material-icons nav-arrow">chevron_left</span>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/investment'])) ?>">
                <a class="nav-sub-item <?= (str_contains($uri,'/admin/investment') && !str_contains($uri,'/trades') && !str_contains($uri,'/apply-profit') && !str_contains($uri,'/withdrawals')) ? 'active' : '' ?>"
                   href="<?= url('/admin/investment') ?>">
                    <span class="nav-sub-dot"></span>سرمایه‌گذاری‌ها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/investment/trades')) ?>" href="<?= url('/admin/investment/trades') ?>">
                    <span class="nav-sub-dot"></span>تریدها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/investment/apply-profit')) ?>" href="<?= url('/admin/investment/apply-profit') ?>">
                    <span class="nav-sub-dot"></span>اعمال سود/ضرر
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/investment/withdrawals')) ?>" href="<?= url('/admin/investment/withdrawals') ?>">
                    <span class="nav-sub-dot"></span>برداشت سرمایه
                </a>
            </div>
        </div>

        <!-- ─── تسک‌ها ─── -->
        <div class="nav-section">
            <div class="nav-section-label">تسک‌ها و تبلیغات</div>

            <a class="nav-item <?= e($ac('/admin/ad-tasks')) ?>" href="<?= url('/admin/ad-tasks') ?>">
                <span class="material-icons nav-icon">assignment</span>
                <span class="nav-label">مدیریت تسک‌ها</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/task-executions')) ?>" href="<?= url('/admin/task-executions') ?>">
                <span class="material-icons nav-icon">task_alt</span>
                <span class="nav-label">اجراهای تسک</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/task-disputes')) ?>" href="<?= url('/admin/task-disputes') ?>">
                <span class="material-icons nav-icon">gavel</span>
                <span class="nav-label">اختلافات تسک</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/task-rechecks')) ?>" href="<?= url('/admin/task-rechecks') ?>">
                <span class="material-icons nav-icon">rate_review</span>
                <span class="nav-label">بررسی مجدد</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/custom-tasks')) ?>" href="<?= url('/admin/custom-tasks') ?>">
                <span class="material-icons nav-icon">check_box</span>
                <span class="nav-label">تسک‌های سفارشی</span>
            </a>

            <div class="nav-item has-sub <?= e($openIf(['/admin/banners'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">view_carousel</span>
                <span class="nav-label">بنرها و تبلیغات</span>
                <span class="material-icons nav-arrow">chevron_left</span>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/banners'])) ?>">
                <a class="nav-sub-item <?= (str_contains($uri,'/admin/banners') && !str_contains($uri,'/placements')) ? 'active' : '' ?>"
                   href="<?= url('/admin/banners') ?>">
                    <span class="nav-sub-dot"></span>مدیریت بنرها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/banners/placements')) ?>" href="<?= url('/admin/banners/placements') ?>">
                    <span class="nav-sub-dot"></span>جایگاه‌های بنر
                </a>
            </div>
        </div>

        <!-- ─── محتوا ─── -->
        <div class="nav-section">
            <div class="nav-section-label">محتوا و مدیریت</div>

            <div class="nav-item has-sub <?= e($openIf(['/admin/content', '/admin/influencer', '/admin/seo-keywords'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">campaign</span>
                <span class="nav-label">محتوا و رسانه</span>
                <span class="material-icons nav-arrow">chevron_left</span>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/content', '/admin/influencer', '/admin/seo-keywords'])) ?>">
                <a class="nav-sub-item <?= (str_contains($uri,'/admin/content') && !str_contains($uri,'/revenues')) ? 'active' : '' ?>"
                   href="<?= url('/admin/content') ?>">
                    <span class="nav-sub-dot"></span>مدیریت محتوا
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/content/revenues')) ?>" href="<?= url('/admin/content/revenues') ?>">
                    <span class="nav-sub-dot"></span>درآمد محتوا
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/influencer/orders')) ?>" href="<?= url('/admin/influencer/orders') ?>">
                    <span class="nav-sub-dot"></span>سفارش‌های اینفلوئنسر
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/influencer/profiles')) ?>" href="<?= url('/admin/influencer/profiles') ?>">
                    <span class="nav-sub-dot"></span>پروفایل‌های اینفلوئنسر
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/influencer/verifications')) ?>" href="<?= url('/admin/influencer/verifications') ?>">
                    <span class="nav-sub-dot"></span>درخواست‌های تایید
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/influencer/disputes')) ?>" href="<?= url('/admin/influencer/disputes') ?>">
                    <span class="nav-sub-dot"></span>اختلاف‌ها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/seo-keywords')) ?>" href="<?= url('/admin/seo-keywords') ?>">
                    <span class="nav-sub-dot"></span>کلمات کلیدی SEO
                </a>
            </div>

            <div class="nav-item has-sub <?= e($openIf(['/admin/vitrine'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">storefront</span>
                <span class="nav-label">ویترین</span>
                <span class="material-icons nav-arrow">chevron_left</span>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/vitrine'])) ?>">
                <a class="nav-sub-item <?= e($ac('/admin/vitrine') && !str_contains($uri,'/settings') ? 'active' : '') ?>"
                   href="<?= url('/admin/vitrine') ?>">
                    <span class="nav-sub-dot"></span>مدیریت آگهی‌ها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/vitrine/settings')) ?>"
                   href="<?= url('/admin/vitrine/settings') ?>">
                    <span class="nav-sub-dot"></span>تنظیمات ویترین
                </a>
            </div>

            <a class="nav-item <?= e($ac('/admin/lottery')) ?>" href="<?= url('/admin/lottery') ?>">
                <span class="material-icons nav-icon">casino</span>
                <span class="nav-label">قرعه‌کشی</span>
            </a>

            <div class="nav-item has-sub <?= e($openIf(['/admin/coupons'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">local_offer</span>
                <span class="nav-label">کوپن‌ها</span>
                <span class="material-icons nav-arrow">chevron_left</span>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/coupons'])) ?>">
                <a class="nav-sub-item <?= (str_contains($uri,'/admin/coupons') && !str_contains($uri,'/redemptions') && !str_contains($uri,'/statistics')) ? 'active' : '' ?>"
                   href="<?= url('/admin/coupons') ?>">
                    <span class="nav-sub-dot"></span>لیست کوپن‌ها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/coupons/statistics')) ?>" href="<?= url('/admin/coupons/statistics') ?>">
                    <span class="nav-sub-dot"></span>آمار کوپن‌ها
                </a>
            </div>
        </div>

        <!-- ─── پشتیبانی ─── -->
        <div class="nav-section">
            <div class="nav-section-label">پشتیبانی</div>

            <a class="nav-item <?= e($ac('/admin/tickets')) ?>" href="<?= url('/admin/tickets') ?>">
                <span class="material-icons nav-icon">support_agent</span>
                <span class="nav-label">تیکت‌های پشتیبانی</span>
                <?php
                try {
                    $tOpen = \Core\Database::getInstance()->selectOne("SELECT COUNT(*) as c FROM tickets WHERE status IN ('open','pending')")->c ?? 0;
                    if ($tOpen > 0): ?>
                    <span class="nav-badge badge-red"><?= fa_number($tOpen) ?></span>
                    <?php endif;
                } catch(\Exception $e) {}
                ?>
            </a>

            <a class="nav-item <?= e($ac('/admin/bug-reports')) ?>" href="<?= url('/admin/bug-reports') ?>">
                <span class="material-icons nav-icon">bug_report</span>
                <span class="nav-label">گزارش‌های باگ</span>
                <?php
                try {
                    $bugOpen = \Core\Database::getInstance()->selectOne("SELECT COUNT(*) as c FROM bug_report_comments WHERE id > 0")->c ?? 0;
                    // just show static badge if we can't query
                } catch(\Exception $e) {}
                ?>
            </a>

            <a class="nav-item <?= e($ac('/admin/notifications/send')) ?>" href="<?= url('/admin/notifications/send') ?>">
                <span class="material-icons nav-icon">notification_add</span>
                <span class="nav-label">ارسال اعلان</span>
            </a>

            <a class="nav-item <?= (str_contains($uri,'/admin/notifications') && !str_contains($uri,'/send')) ? 'active' : '' ?>"
               href="<?= url('/admin/notifications') ?>">
                <span class="material-icons nav-icon">notifications</span>
                <span class="nav-label">اعلان‌ها</span>
            </a>
        </div>

        <!-- ─── گزارش‌ها ─── -->
        <div class="nav-section">
            <div class="nav-section-label">گزارش‌ها و آنالیتیکس</div>

            <a class="nav-item <?= e($ac('/admin/kpi')) ?>" href="<?= url('/admin/kpi') ?>">
                <span class="material-icons nav-icon">analytics</span>
                <span class="nav-label">KPI و آنالیتیکس</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/fraud')) ?>" href="<?= url('/admin/fraud') ?>">
                <span class="material-icons nav-icon">security</span>
                <span class="nav-label">داشبورد ضدتقلب</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/audit-trail')) ?>" href="<?= url('/admin/audit-trail') ?>">
                <span class="material-icons nav-icon">manage_search</span>
                <span class="nav-label">Audit Trail</span>
            </a>

            <a class="nav-item <?= $ac('/admin/logs') || $ac('/admin/activity-logs') ? 'active' : '' ?>"
               href="<?= url('/admin/logs') ?>">
                <span class="material-icons nav-icon">history</span>
                <span class="nav-label">لاگ فعالیت‌ها</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/export')) ?>" href="<?= url('/admin/export') ?>">
                <span class="material-icons nav-icon">file_download</span>
                <span class="nav-label">خروجی CSV</span>
            </a>
        </div>

        <!-- ─── سیستم ─── -->
        <div class="nav-section">
            <div class="nav-section-label">سیستم</div>

            <a class="nav-item <?= e($ac('/admin/cron')) ?>" href="<?= url('/admin/cron') ?>">
                <span class="material-icons nav-icon">schedule</span>
                <span class="nav-label">Cron Jobs</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/email-queue')) ?>" href="<?= url('/admin/email-queue') ?>">
                <span class="material-icons nav-icon">mark_email_unread</span>
                <span class="nav-label">صف ایمیل</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/api-tokens')) ?>" href="<?= url('/admin/api-tokens') ?>">
                <span class="material-icons nav-icon">vpn_key</span>
                <span class="nav-label">توکن‌های API</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/cache')) ?>" href="<?= url('/admin/cache') ?>">
                <span class="material-icons nav-icon">cached</span>
                <span class="nav-label">مدیریت Cache</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/features')) ?>" href="<?= url('/admin/features') ?>">
                <span class="material-icons nav-icon">toggle_on</span>
                <span class="nav-label">Feature Flags</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/backups')) ?>" href="<?= url('/admin/backups') ?>">
                <span class="material-icons nav-icon">backup</span>
                <span class="nav-label">پشتیبان‌گیری دیتابیس</span>
            </a>
        </div>

        <!-- ─── تنظیمات ─── -->
        <div class="nav-section">
            <div class="nav-section-label">تنظیمات</div>

            <a class="nav-item <?= e($ac('/admin/settings')) ?>" href="<?= url('/admin/settings') ?>">
                <span class="material-icons nav-icon">settings</span>
                <span class="nav-label">تنظیمات سیستم</span>
            </a>

            <a class="nav-item <?= e($ac('/admin/captcha')) ?>" href="<?= url('/admin/captcha/settings') ?>">
                <span class="material-icons nav-icon">verified</span>
                <span class="nav-label">تنظیمات کپچا</span>
            </a>
        </div>

        <!-- ─── مانیتورینگ سیستم (Sentry) ─── -->
        <div class="nav-section">
            <div class="nav-section-label">مانیتورینگ</div>

            <div class="nav-item has-sub <?= e($openIf(['/admin/sentry'])) ?>"
                 onclick="toggleAdminSub(this)">
                <span class="material-icons nav-icon">shield</span>
                <span class="nav-label">مانیتورینگ سیستم</span>
                <span class="material-icons nav-arrow">chevron_left</span>
                <?php
                // نمایش تعداد خطاهای unresolved
                try {
                    $db = \Core\Database::getInstance();
                    $unresolved = $db->query("SELECT COUNT(*) as c FROM sentry_issues WHERE status = 'unresolved'")->fetch(\PDO::FETCH_OBJ);
                    $unresolvedCount = (int)($unresolved->c ?? 0);
                    if ($unresolvedCount > 0): ?>
                        <span class="nav-badge" style="background:#fed7d7;color:#c53030;font-size:0.7rem;padding:2px 6px;border-radius:10px;margin-right:auto;">
                            <?= fa_number($unresolvedCount) ?>
                        </span>
                    <?php endif;
                } catch(\Throwable $e) {}
                ?>
            </div>
            <div class="nav-submenu <?= e($openIf(['/admin/sentry'])) ?>">
                <a class="nav-sub-item <?= e($ac('/admin/sentry') && !str_contains($uri, '/admin/sentry/')) ?>" href="<?= url('/admin/sentry') ?>">
                    <span class="nav-sub-dot"></span>داشبورد کلی
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/sentry/issues')) ?>" href="<?= url('/admin/sentry/issues') ?>">
                    <span class="nav-sub-dot"></span>خطاها و Issues
                    <?php if (isset($unresolvedCount) && $unresolvedCount > 0): ?>
                        <span style="margin-right:auto;background:#fed7d7;color:#c53030;font-size:0.65rem;padding:1px 5px;border-radius:8px;">
                            <?= fa_number($unresolvedCount) ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/sentry/performance')) ?>" href="<?= url('/admin/sentry/performance') ?>">
                    <span class="nav-sub-dot"></span>عملکرد سیستم
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/sentry/analytics')) ?>" href="<?= url('/admin/sentry/analytics') ?>">
                    <span class="nav-sub-dot"></span>تحلیل و روندها
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/sentry/alerts')) ?>" href="<?= url('/admin/sentry/alerts') ?>">
                    <span class="nav-sub-dot"></span>مدیریت هشدارها
                    <?php
                    try {
                        $activeAlerts = $db->query("SELECT COUNT(*) as c FROM system_alerts WHERE is_active = 1 AND acknowledged_at IS NULL")->fetch(\PDO::FETCH_OBJ);
                        $alertCount = (int)($activeAlerts->c ?? 0);
                        if ($alertCount > 0): ?>
                            <span style="margin-right:auto;background:#feebc8;color:#c05621;font-size:0.65rem;padding:1px 5px;border-radius:8px;">
                                <?= fa_number($alertCount) ?>
                            </span>
                        <?php endif;
                    } catch(\Throwable $e) {}
                    ?>
                </a>
                <a class="nav-sub-item <?= e($ac('/admin/sentry/audit')) ?>" href="<?= url('/admin/sentry/audit') ?>">
                    <span class="nav-sub-dot"></span>Audit Trail
                </a>
            </div>
        </div>

        <!-- بازگشت -->
        <div class="nav-section">
            <a class="nav-item" href="<?= url('/dashboard') ?>">
                <span class="material-icons nav-icon">home</span>
                <span class="nav-label">بازگشت به سایت</span>
            </a>
        </div>

    </nav>

    <!-- Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-footer-links">
            <a class="sidebar-footer-btn" href="<?= url('/admin/settings') ?>">
                <span class="material-icons">settings</span>
                <span>تنظیمات</span>
            </a>
            <form method="POST" action="<?= url('/logout') ?>" style="flex:1">
                <?= csrf_field() ?>
                <button type="submit" class="sidebar-footer-btn w-100" style="color:var(--red)">
                    <span class="material-icons">logout</span>
                    <span>خروج</span>
                </button>
            </form>
        </div>
    </div>

</aside>