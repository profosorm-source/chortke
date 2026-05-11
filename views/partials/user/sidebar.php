<?php
// ─── User Sidebar — ساختار جدید ───
$uri       = $_SERVER['REQUEST_URI'] ?? '/';
$active    = fn(string $p) => str_contains($uri, $p);
$exact     = fn(string $p) => rtrim(strtok($uri,'?'),'/') === rtrim($p,'/');
$anyActive = fn(array $ps) => array_reduce($ps, fn($c,$p) => $c || str_contains($uri,$p), false);

// Fetch active disputes badge count - استفاده از db() helper بجای Container
$__db = db();
$__uid = user_id();
$__disputeBadge = 0;
if ($__uid) {
    $__disputeRes = $__db->fetch("SELECT COUNT(*) as total FROM disputes WHERE (user_id = ? OR target_user_id = ?) AND status NOT IN ('resolved','closed','resolved_advertiser','resolved_executor','admin_closed')", [$__uid, $__uid]);
    $__disputeBadge = (int)($__disputeRes->total ?? 0);

    // Fetch new influencer orders count
    $__infRes = $__db->fetch("SELECT COUNT(*) as total FROM story_orders WHERE influencer_user_id = ? AND status = 'paid'", [$__uid]);
    $__influencerBadge = (int)($__infRes->total ?? 0);
}
?>
<div class="sidebar" id="mainSidebar">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <a href="<?= url('/dashboard') ?>" class="sb-logo">
    <?php $__logo = site_logo('main'); ?>
    <?php if ($__logo): ?>
      <img src="<?= e($__logo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>" class="sb-logo-img" style="max-height:40px;max-width:140px;object-fit:contain;">
    <?php else: ?>
      <div class="sb-logo-icon"><span class="material-icons">account_balance</span></div>
      <span class="sb-logo-text"><?= e(setting('site_name','چرتکه')) ?></span>
    <?php endif; ?>
  </a>

  <ul class="sidebar-menu">
    <li>
      <a href="<?= url('/dashboard') ?>" class="<?= $active('/dashboard')?'active':'' ?>">
        <span class="material-icons">dashboard</span><span class="menu-title">داشبورد</span>
      </a>
    </li>

    <!-- ═══ کسب درآمد ═══ -->
    <li class="menu-section-title text-info"><i class="material-icons small align-middle">engineering</i> کسب درآمد (انجام‌دهنده)</li>

    <li>
      <a href="<?= url('/tasks') ?>" class="<?= $exact('/tasks') ? 'active' : '' ?>">
        <span class="material-icons">monetization_on</span><span class="menu-title">تسک‌های درآمدزا</span>
      </a>
    </li>

    <?php $o = $active('/influencer')&&!$active('/influencer/ads'); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="inf-in">
        <span class="material-icons">stars</span><span class="menu-title">Influencer</span>
        <?php if(($__influencerBadge ?? 0) > 0): ?>
           <span class="badge bg-danger rounded-pill ms-auto me-2" style="font-size: 0.6rem;"><?= $__influencerBadge ?></span>
        <?php endif; ?>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/influencer') ?>" class="<?= $exact('/influencer')?'active':'' ?>"><span class="material-icons">person_pin</span><span class="menu-title">پروفایل من</span></a></li>
        <li><a href="<?= url('/influencer/register') ?>" class="<?= $active('/influencer/register')?'active':'' ?>"><span class="material-icons">how_to_reg</span><span class="menu-title">ثبت پیج</span></a></li>
        <li><a href="<?= url('/influencer/orders') ?>" class="<?= $active('/influencer/orders')?'active':'' ?>">
            <span class="material-icons">pending_actions</span><span class="menu-title">سفارش‌های دریافتی</span>
            <?php if(($__influencerBadge ?? 0) > 0): ?>
               <span class="badge bg-danger rounded-pill ms-auto" style="font-size: 0.6rem;"><?= $__influencerBadge ?></span>
            <?php endif; ?>
        </a></li>
      </ul>
    </li>

    <?php $o = $active('/adtube')&&!$active('/adtube/ads'); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="adtube-in">
        <span class="material-icons">play_circle</span><span class="menu-title">Adtube</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/adtube') ?>" class="<?= $exact('/adtube')?'active':'' ?>"><span class="material-icons">video_library</span><span class="menu-title">ویدیوهای موجود</span></a></li>
        <li><a href="<?= url('/adtube/history') ?>" class="<?= $active('/adtube/history')?'active':'' ?>"><span class="material-icons">history</span><span class="menu-title">تاریخچه</span></a></li>
      </ul>
    </li>


    <?php $o = $active('/content'); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="cont-in">
        <span class="material-icons">video_library</span><span class="menu-title">درآمد از محتوا</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/content') ?>" class="<?= $exact('/content')?'active':'' ?>"><span class="material-icons">collections</span><span class="menu-title">محتواهای من</span></a></li>
        <li><a href="<?= url('/content/create') ?>" class="<?= $active('/content/create')?'active':'' ?>"><span class="material-icons">add_circle</span><span class="menu-title">ارسال محتوا</span></a></li>
        <li><a href="<?= url('/content/revenues') ?>" class="<?= $active('/content/revenues')?'active':'' ?>"><span class="material-icons">payments</span><span class="menu-title">درآمدهای من</span></a></li>
      </ul>
    </li>

    <!-- ═══ تبلیغات من ═══ -->
    <li class="menu-section-title text-warning"><i class="material-icons small align-middle">business_center</i> پنل کارفرمایان (تبلیغ‌دهنده)</li>

    <?php $o = $active('/ads'); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="my-ads-uni">
        <span class="material-icons">campaign</span><span class="menu-title">آگهی‌های من</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/ads') ?>" class="<?= $exact('/ads')?'active':'' ?>"><span class="material-icons">view_list</span><span class="menu-title">لیست آگهی‌ها</span></a></li>
        <li><a href="<?= url('/ads/create') ?>" class="<?= $active('/ads/create')?'active':'' ?>"><span class="material-icons">add_circle</span><span class="menu-title">ثبت آگهی جدید</span></a></li>
      </ul>
    </li>

    <!-- همچنین موارد دیگر مربوط به تبلیغات خاص که هنوز در یکپارچه سازی کامل نیستند -->
    <?php $o = $active('/influencer/ads'); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="inf-adv">
        <span class="material-icons">auto_awesome</span><span class="menu-title">Influencer</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/influencer/ads') ?>" class="<?= $exact('/influencer/ads')?'active':'' ?>"><span class="material-icons">search</span><span class="menu-title">پیدا کردن اینفلوئنسر</span></a></li>
        <li><a href="<?= url('/influencer/ads/my-orders') ?>" class="<?= $active('/influencer/ads/my-orders')?'active':'' ?>"><span class="material-icons">list_alt</span><span class="menu-title">سفارش‌های من</span></a></li>
      </ul>
    </li>
    <?php $vitActive = $active('/vitrine'); ?>
    <li class="has-submenu <?= $vitActive?'open':'' ?>">
      <a href="#" class="<?= $vitActive?'active':'' ?>" data-submenu-toggle="vitrine">
        <span class="material-icons">storefront</span><span class="menu-title">ویترین</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/vitrine') ?>" class="<?= $exact('/vitrine')?'active':'' ?>">
          <span class="material-icons">store</span><span class="menu-title">بازار فروش</span>
        </a></li>
        <li><a href="<?= url('/vitrine/wanted') ?>" class="<?= $active('/vitrine/wanted')&&!$active('/create')?'active':'' ?>">
          <span class="material-icons">search</span><span class="menu-title">خریداران</span>
        </a></li>
        <li><a href="<?= url('/vitrine/my-listings') ?>" class="<?= $active('/vitrine/my-listings')?'active':'' ?>">
          <span class="material-icons">list_alt</span><span class="menu-title">آگهی‌های من</span>
        </a></li>
        <li><a href="<?= url('/vitrine/my-purchases') ?>" class="<?= $active('/vitrine/my-purchases')?'active':'' ?>">
          <span class="material-icons">shopping_cart</span><span class="menu-title">خریدهای من</span>
        </a></li>
      </ul>
    </li>

    <!-- ═══ مستقل ═══ -->
    <li class="menu-section-title">مستقل</li>

    <?php $o = $active('/investment'); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="inv">
        <span class="material-icons">trending_up</span><span class="menu-title">سرمایه‌گذاری</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/investment') ?>" class="<?= $exact('/investment')?'active':'' ?>"><span class="material-icons">analytics</span><span class="menu-title">پلن‌های من</span></a></li>
        <li><a href="<?= url('/investment/create') ?>" class="<?= $active('/investment/create')?'active':'' ?>"><span class="material-icons">add_business</span><span class="menu-title">سرمایه‌گذاری جدید</span></a></li>
        <li><a href="<?= url('/investment/profit-history') ?>" class="<?= $active('/investment/profit-history')?'active':'' ?>"><span class="material-icons">receipt_long</span><span class="menu-title">تاریخچه سود</span></a></li>
      </ul>
    </li>

    <li>
      <a href="<?= url('/lottery') ?>" class="<?= $active('/lottery')?'active':'' ?>">
        <span class="material-icons">card_giftcard</span><span class="menu-title">قرعه‌کشی</span>
      </a>
    </li>

    <li>
      <a href="<?= url('/prediction') ?>" class="<?= $active('/prediction')?'active':'' ?>">
        <span class="material-icons">sports_soccer</span><span class="menu-title">پیش‌بینی بازی‌ها</span>
      </a>
    </li>
    <li>
      <a href="<?= url('/startup-banner') ?>" class="<?= $active('/startup-banner')?'active':'' ?>">
        <span class="material-icons">rocket_launch</span><span class="menu-title">کسب‌وکار نوپا</span>
      </a>
    </li>

    <?php $o = $active('/referral'); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="ref">
        <span class="material-icons">group_add</span><span class="menu-title">دعوت از دوستان</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/referral') ?>" class="<?= $exact('/referral')?'active':'' ?>"><span class="material-icons">people</span><span class="menu-title">داشبورد معرفی</span></a></li>
        <li><a href="<?= url('/referral/commissions') ?>" class="<?= $active('/referral/commissions')?'active':'' ?>"><span class="material-icons">percent</span><span class="menu-title">کمیسیون‌های من</span></a></li>
      </ul>
    </li>

    <li>
      <a href="<?= url('/level') ?>" class="<?= $active('/level')?'active':'' ?>">
        <span class="material-icons">workspace_premium</span><span class="menu-title">سطح کاربری</span>
      </a>
    </li>

    <!-- ═══ مالی ═══ -->
    <li class="menu-section-title">مالی</li>

    <?php $o = $anyActive(['/wallet','/withdrawal','/withdrawals','/manual-deposit','/manual-deposits','/crypto-deposit','/crypto-deposits','/bank-cards']); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="fin">
        <span class="material-icons">account_balance_wallet</span><span class="menu-title">کیف پول و مالی</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/wallet') ?>" class="<?= $exact('/wallet')?'active':'' ?>"><span class="material-icons">account_balance_wallet</span><span class="menu-title">کیف پول</span></a></li>
        <li><a href="<?= url('/wallet/history') ?>" class="<?= $active('/wallet/history')?'active':'' ?>"><span class="material-icons">receipt_long</span><span class="menu-title">تاریخچه تراکنش‌ها</span></a></li>
        <li class="submenu-divider"></li>
        <li><a href="<?= url('/wallet/deposit/manual') ?>" class="<?= $active('/wallet/deposit/manual')?'active':'' ?>"><span class="material-icons">account_balance</span><span class="menu-title">واریز کارت به کارت</span></a></li>
        <li><a href="<?= url('/wallet/deposit/crypto') ?>" class="<?= $active('/wallet/deposit/crypto')?'active':'' ?>"><span class="material-icons">currency_bitcoin</span><span class="menu-title">واریز تتر USDT</span></a></li>
        <li class="submenu-divider"></li>
        <li><a href="<?= url('/wallet/withdraw') ?>" class="<?= $active('/wallet/withdraw')?'active':'' ?>"><span class="material-icons">remove_circle</span><span class="menu-title">برداشت وجه</span></a></li>
        <li><a href="<?= url('/bank-cards') ?>" class="<?= $active('/bank-cards')&&!$active('/create')?'active':'' ?>"><span class="material-icons">credit_card</span><span class="menu-title">کارت‌های بانکی</span></a></li>
      </ul>
    </li>

    <!-- ═══ حساب کاربری ═══ -->
    <li class="menu-section-title">حساب کاربری</li>

    <?php $o = $anyActive(['/profile','/kyc','/sessions','/api-tokens']); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="acc">
        <span class="material-icons">manage_accounts</span><span class="menu-title">حساب کاربری</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/profile') ?>" class="<?= $active('/profile')?'active':'' ?>"><span class="material-icons">person</span><span class="menu-title">پروفایل من</span></a></li>
        <li><a href="<?= url('/kyc') ?>" class="<?= $exact('/kyc')?'active':'' ?>"><span class="material-icons">verified_user</span><span class="menu-title">احراز هویت (KYC)</span></a></li>
        <li><a href="<?= url('/sessions') ?>" class="<?= $active('/sessions')?'active':'' ?>"><span class="material-icons">devices</span><span class="menu-title">جلسات فعال</span></a></li>
        <li><a href="<?= url('/api-tokens') ?>" class="<?= $active('/api-tokens')?'active':'' ?>"><span class="material-icons">vpn_key</span><span class="menu-title">توکن‌های API</span></a></li>
      </ul>
    </li>

    <li>
      <a href="<?= url('/disputes') ?>" class="<?= $active('/disputes')?'active':'' ?>">
        <span class="material-icons">gavel</span>
        <span class="menu-title">اختلافات و شکایات</span>
        <?php if($__disputeBadge > 0): ?>
           <span class="badge bg-danger rounded-pill ms-auto" style="font-size: 0.7rem;"><?= $__disputeBadge ?></span>
        <?php endif; ?>
      </a>
    </li>

    <!-- ═══ پشتیبانی ═══ -->
    <li class="menu-section-title">پشتیبانی</li>

    <?php $o = $anyActive(['/tickets','/bug-reports','/notifications','/search']); ?>
    <li class="has-submenu <?= $o?'open':'' ?>">
      <a href="#" class="<?= $o?'active':'' ?>" data-submenu-toggle="sup">
        <span class="material-icons">support_agent</span><span class="menu-title">پشتیبانی</span>
        <span class="material-icons submenu-arrow">expand_more</span>
      </a>
      <ul class="submenu">
        <li><a href="<?= url('/tickets') ?>" class="<?= $active('/tickets')&&!$active('/create')?'active':'' ?>"><span class="material-icons">confirmation_number</span><span class="menu-title">تیکت‌های من</span></a></li>
        <li><a href="<?= url('/tickets/create') ?>" class="<?= $active('/tickets/create')?'active':'' ?>"><span class="material-icons">add_comment</span><span class="menu-title">تیکت جدید</span></a></li>
        <li class="submenu-divider"></li>
        <li><a href="<?= url('/notifications') ?>" class="<?= $active('/notifications')?'active':'' ?>"><span class="material-icons">notifications</span><span class="menu-title">اعلان‌ها</span></a></li>
        <li><a href="<?= url('/bug-reports') ?>" class="<?= $active('/bug-reports')?'active':'' ?>"><span class="material-icons">bug_report</span><span class="menu-title">گزارش مشکل</span></a></li>
        <li><a href="<?= url('/search') ?>" class="<?= $exact('/search')?'active':'' ?>"><span class="material-icons">search</span><span class="menu-title">جستجو</span></a></li>
      </ul>
    </li>

    <li class="menu-section-title"></li>
    <li>
      <a href="#" onclick="event.preventDefault(); document.getElementById('sidebarLogoutForm').submit();" class="text-danger">
        <span class="material-icons">logout</span><span class="menu-title">خروج</span>
      </a>
    </li>

    <form method="POST" action="<?= url('/logout') ?>" id="sidebarLogoutForm" style="display:none;">
      <?= csrf_field() ?>
    </form>
  </ul>
</div>
