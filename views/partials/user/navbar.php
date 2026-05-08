<?php
// ─── User Navbar — winner ticker + dropdowns ───
// متغیرها: $fullName, $tier, $kycLabel, $notifCount, $topNotifications, $avatarUrl, $title
// $lotteryWinners از layout یا controller پاس می‌شه

$lotteryWinners = $lotteryWinners ?? [];
?>
<div class="topbar">

  <!-- Mobile menu btn -->
  <button class="topbar-icon d-lg-none" id="sidebarToggle" style="border:none;cursor:pointer">
    <span class="material-icons">menu</span>
  </button>

  <!-- Page title -->
  <h4 class="mb-0 d-none d-md-block"><?= e($title ?? 'داشبورد') ?></h4>

  <!-- Winner Ticker -->
  <div class="winner-ticker">
    <div class="wt-label">
      <span class="material-icons">emoji_events</span>
      برندگان:
    </div>
    <div class="wt-scroll">
      <div class="wt-inner" id="winnerTicker">
        <?php if (!empty($lotteryWinners)): ?>
          <?php foreach ($lotteryWinners as $w): $w = is_array($w)?((object)$w):$w; ?>
            <span class="wt-name"><?= e($w->winner_name ?? 'ناشناس') ?></span>
          <?php endforeach; ?>
          <?php foreach ($lotteryWinners as $w): $w = is_array($w)?((object)$w):$w; ?>
            <span class="wt-name"><?= e($w->winner_name ?? 'ناشناس') ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="wt-name" style="opacity:.6;font-weight:500">نتایج قرعه‌کشی به‌زودی اعلام می‌شود</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Search Box -->
  <div class="topbar-search" id="topbarSearch">
    <span class="material-icons ts-icon">search</span>
    <input type="text" class="ts-input" id="tsInput" placeholder="جستجو..." autocomplete="off" />
    <div class="ts-results" id="tsResults"></div>
  </div>

  <!-- Actions -->
  <div class="topbar-actions">

    <!-- Ticket -->
    <a href="<?= url('/tickets') ?>" class="topbar-icon" title="تیکت‌های پشتیبانی">
      <span class="material-icons">support_agent</span>
      <?php if ((int)($openTicketCount??0) > 0): ?>
        <span class="badge"><?= (int)$openTicketCount ?></span>
      <?php endif; ?>
    </a>

    <!-- Notifications -->
    <div class="topbar-icon" data-dd-toggle="notif" title="اعلان‌ها">
      <span class="material-icons">notifications</span>
      <?php if ((int)($notifCount??0) > 0): ?>
        <span class="badge"><?= (int)$notifCount ?></span>
      <?php endif; ?>
    </div>

    <!-- Settings -->
    <div class="topbar-icon" data-dd-toggle="settings" title="تنظیمات">
      <span class="material-icons">settings</span>
    </div>

    <!-- Logout -->
    <div class="topbar-icon" onclick="document.getElementById('lf').submit()" title="خروج" style="cursor:pointer">
      <span class="material-icons">logout</span>
    </div>
    <form id="lf" method="POST" action="<?= url('/logout') ?>" style="display:none"><?= csrf_field() ?></form>

    <!-- User -->
    <div class="user-info">
      <div class="user-details">
        <p class="user-name"><?= e($fullName) ?></p>
        <p class="user-role"><?= e(strtoupper((string)($tier??'SILVER'))) ?> | <?= e($kycLabel??'در انتظار') ?></p>
      </div>
      <div class="user-avatar">
        <img src="<?= e($avatarUrl ?? asset('uploads/avatars/default-avatar.png')) ?>" alt="">
      </div>
    </div>

  </div><!-- /topbar-actions -->

  <!-- Notification Dropdown -->
  <div data-dd-menu="notif">
    <div class="dd-header">
      اعلان‌ها
      <a href="<?= url('/notifications') ?>" class="dd-view-all">مشاهده همه</a>
    </div>
    <div class="dd-divider"></div>
    <?php if (empty($topNotifications)): ?>
      <div class="dd-empty">اعلان جدیدی ندارید.</div>
    <?php else: ?>
      <?php foreach ($topNotifications as $n): $n=is_array($n)?((object)$n):$n; ?>
        <a href="<?= url('/notifications') ?>" data-dd-item>
          <span class="material-icons">notifications</span>
          <span>
            <div class="dd-title"><?= e($n->title ?? 'اعلان') ?></div>
            <div class="dd-message"><?= e($n->message ?? '') ?></div>
            <div class="dd-time"><?= e($n->created_at ? jdate($n->created_at) : '') ?></div>
          </span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Settings Dropdown -->
  <div data-dd-menu="settings">
    <a href="<?= url('/profile') ?>" data-dd-item><span class="material-icons">person</span><span>ویرایش پروفایل</span></a>
    <a href="<?= url('/kyc') ?>" data-dd-item><span class="material-icons">verified_user</span><span>احراز هویت KYC</span></a>
    <a href="<?= url('/bank-cards') ?>" data-dd-item><span class="material-icons">credit_card</span><span>کارت‌های بانکی</span></a>
    <a href="<?= url('/security') ?>" data-dd-item><span class="material-icons">lock</span><span>امنیت</span></a>
    <a href="<?= url('/sessions') ?>" data-dd-item><span class="material-icons">devices</span><span>نشست‌های فعال</span></a>
    <a href="<?= url('/api-tokens') ?>" data-dd-item><span class="material-icons">vpn_key</span><span>توکن‌های API</span></a>
  </div>

</div>

<script>
(function(){
  var links = [
    {label:'داشبورد', icon:'dashboard', url:'/dashboard'},
    {label:'کیف پول', icon:'account_balance_wallet', url:'/wallet'},
    {label:'واریز', icon:'add_circle', url:'/wallet/deposit'},
    {label:'برداشت', icon:'remove_circle', url:'/wallet/withdraw'},
    {label:'تسک‌ها', icon:'task_alt', url:'/tasks'},
    {label:'تاریخچه تسک‌ها', icon:'history', url:'/tasks/history'},
    {label:'تبلیغات', icon:'campaign', url:'/advertiser'},
    {label:'لاتاری', icon:'casino', url:'/lottery'},
    {label:'سرمایه‌گذاری', icon:'trending_up', url:'/investment'},
    {label:'پیج‌های من', icon:'share', url:'/social-accounts'},
    {label:'تراکنش‌ها', icon:'receipt_long', url:'/wallet/history'},
    {label:'پروفایل', icon:'person', url:'/profile'},
    {label:'احراز هویت KYC', icon:'verified_user', url:'/kyc'},
    {label:'کارت بانکی', icon:'credit_card', url:'/bank-cards'},
    {label:'امنیت', icon:'lock', url:'/security'},
    {label:'تیکت‌ها', icon:'support_agent', url:'/tickets'},
    {label:'اعلان‌ها', icon:'notifications', url:'/notifications'},
    {label:'زیرمجموعه‌ها', icon:'people', url:'/referrals'},
  ];
  var inp = document.getElementById('tsInput');
  var res = document.getElementById('tsResults');
  if(!inp) return;
  inp.addEventListener('input', function(){
    var q = this.value.trim();
    if(!q){ res.classList.remove('open'); return; }
    var filtered = links.filter(function(l){ return l.label.indexOf(q) !== -1; });
    if(!filtered.length){
      res.innerHTML = '<div class="ts-result-empty">نتیجه‌ای یافت نشد</div>';
    } else {
      res.innerHTML = filtered.slice(0,8).map(function(l){
        return '<a class="ts-result-item" href="'+l.url+'">' +
          '<span class="material-icons">'+l.icon+'</span>' +
          '<span>'+l.label+'</span>' +
        '</a>';
      }).join('');
    }
    res.classList.add('open');
  });
  inp.addEventListener('keydown', function(e){
    if(e.key==='Escape'){ res.classList.remove('open'); inp.blur(); }
    if(e.key==='Enter'){
      var first = res.querySelector('.ts-result-item');
      if(first) window.location = first.getAttribute('href');
    }
  });
  document.addEventListener('click', function(e){
    if(!document.getElementById('topbarSearch').contains(e.target)){
      res.classList.remove('open');
    }
  });
})();
</script>