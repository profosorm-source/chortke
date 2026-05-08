<?php // ─── Footer — دقیقاً مطابق طرح تصویر ─── ?>

<!-- موج بالای فوتر -->
<div style="position:relative;height:80px;overflow:hidden;margin-top:40px;background:#fff">
  <svg viewBox="0 0 1440 80" preserveAspectRatio="none"
       style="position:absolute;bottom:0;width:100%;height:100%">
    <path d="M0,40 C200,80 400,0 600,40 C800,80 1000,0 1200,40 C1350,65 1400,50 1440,40 L1440,80 L0,80 Z"
          fill="#1A1A2E"/>
  </svg>
</div>

<footer style="background:#1A1A2E;color:#aaa;padding:0 0 0;margin-top:0">
  <div class="container-fluid" style="max-width:1200px;margin:0 auto;padding:36px 24px 0">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:32px;margin-bottom:28px">

      <!-- ستون ۱: لوگو + اطلاعات -->
      <div style="display:flex;gap:16px;align-items:flex-start">
        <!-- لوگو -->
        <div style="width:70px;height:70px;border-radius:14px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden">
          <?php $__footerLogo = site_logo('footer') ?? site_logo('main'); ?>
          <?php if ($__footerLogo): ?>
            <img src="<?= e($__footerLogo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>" style="max-width:60px;max-height:60px;object-fit:contain">
          <?php else: ?>
            <span class="material-icons" style="font-size:38px;color:#1A1A2E">account_balance</span>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-size:1.2rem;font-weight:900;color:#fff;margin-bottom:3px"><?= e(strtoupper(setting('site_name','CHORTKE'))) ?></div>
          <div style="font-size:.75rem;color:#888;margin-bottom:12px"><?= e(setting('site_name','چرتکه')) ?></div>
          <div style="font-size:.72rem;color:#888;line-height:1.8">
            <?php $__addr = setting('site_address'); if ($__addr): ?>
              <?= e($__addr) ?><br>
            <?php endif; ?>
            <?php $__phone = setting('contact_phone') ?: setting('phone_support'); if ($__phone): ?>
              <span class="material-icons" style="font-size:12px;vertical-align:middle;color:#F5C518">call</span>
              <?= e($__phone) ?><br>
            <?php endif; ?>
            <?php $__email = setting('contact_email'); if ($__email): ?>
              <span class="material-icons" style="font-size:12px;vertical-align:middle;color:#F5C518">email</span>
              <?= e($__email) ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ستون ۲: لینک‌ها -->
      <div>
        <div style="font-size:.8rem;font-weight:800;color:#ddd;margin-bottom:14px">لینک‌های سریع</div>
        <ul style="list-style:none;padding:0;margin:0">
          <li style="margin-bottom:8px"><a href="<?= url('/') ?>"       style="color:#888;text-decoration:none;font-size:.78rem" onmouseover="this.style.color='#F5C518'" onmouseout="this.style.color='#888'">خانه</a></li>
          <li style="margin-bottom:8px"><a href="<?= url('/terms') ?>"  style="color:#888;text-decoration:none;font-size:.78rem" onmouseover="this.style.color='#F5C518'" onmouseout="this.style.color='#888'">قوانین و مقررات</a></li>
          <li style="margin-bottom:8px"><a href="<?= url('/privacy') ?>" style="color:#888;text-decoration:none;font-size:.78rem" onmouseover="this.style.color='#F5C518'" onmouseout="this.style.color='#888'">حریم خصوصی</a></li>
          <li style="margin-bottom:8px"><a href="<?= url('/contact') ?>" style="color:#888;text-decoration:none;font-size:.78rem" onmouseover="this.style.color='#F5C518'" onmouseout="this.style.color='#888'">تماس با ما</a></li>
        </ul>
      </div>

      <!-- ستون ۳: Connect + آیکون‌ها -->
      <div>
        <div style="font-size:.8rem;font-weight:800;color:#ddd;margin-bottom:14px">ما را دنبال کنید</div>
        <div style="display:flex;gap:10px;margin-bottom:12px">
          <?php $__tg = setting('telegram_support'); if ($__tg): ?>
          <a href="https://t.me/<?= e($__tg) ?>" target="_blank" style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;transition:all .16s;text-decoration:none" onmouseover="this.style.background='#229ED9';this.style.borderColor='#229ED9'" onmouseout="this.style.background='rgba(255,255,255,.08)';this.style.borderColor='rgba(255,255,255,.12)'">
            <span class="material-icons" style="font-size:16px;color:#aaa">send</span>
          </a>
          <?php endif; ?>
          <?php $__ig = setting('instagram_support'); if ($__ig): ?>
          <a href="https://instagram.com/<?= e($__ig) ?>" target="_blank" style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;transition:all .16s;text-decoration:none" onmouseover="this.style.background='#E1306C';this.style.borderColor='#E1306C'" onmouseout="this.style.background='rgba(255,255,255,.08)';this.style.borderColor='rgba(255,255,255,.12)'">
            <span class="material-icons" style="font-size:16px;color:#aaa">camera_alt</span>
          </a>
          <?php endif; ?>
          <?php $__tw = setting('twitter_support'); if ($__tw): ?>
          <a href="https://twitter.com/<?= e($__tw) ?>" target="_blank" style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;transition:all .16s;text-decoration:none" onmouseover="this.style.background='#1DA1F2';this.style.borderColor='#1DA1F2'" onmouseout="this.style.background='rgba(255,255,255,.08)';this.style.borderColor='rgba(255,255,255,.12)'">
            <span class="material-icons" style="font-size:16px;color:#aaa">tag</span>
          </a>
          <?php endif; ?>
          <?php $__site = setting('site_url') ?: url('/'); ?>
          <a href="<?= e($__site) ?>" style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;transition:all .16s;text-decoration:none" onmouseover="this.style.background='#F5C518';this.style.borderColor='#F5C518'" onmouseout="this.style.background='rgba(255,255,255,.08)';this.style.borderColor='rgba(255,255,255,.12)'">
            <span class="material-icons" style="font-size:16px;color:#aaa">language</span>
          </a>
        </div>
      </div>

    </div>
  </div>

  <!-- خط پایین -->
  <div style="background:#12121F;padding:12px 24px;text-align:center;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span style="font-size:.72rem;color:#555">© <?= to_jalali(date('Y-m-d'), 'Y') ?> <?= e(setting('site_name','چرتکه')) ?> — تمامی حقوق محفوظ است</span>
    <?php $__phone = setting('contact_phone') ?: setting('phone_support'); if ($__phone): ?>
    <span style="font-size:.72rem;color:#555">
      <span class="material-icons" style="font-size:13px;vertical-align:middle;color:#F5C518">call</span>
      <?= e($__phone) ?>
    </span>
    <?php endif; ?>
  </div>
</footer>