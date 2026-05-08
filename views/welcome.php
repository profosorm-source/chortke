<?php
$title = 'چرتکه - پلتفرم کسب درآمد آنلاین';
$layout = 'guest';
ob_start();

$isLoggedIn = auth();
$userName = $isLoggedIn ? (auth()->full_name ?? 'کاربر') : '';
$currencyMode = setting('currency_mode') ?? 'irt';
$currencyLabel = ($currencyMode === 'usdt') ? 'USDT' : 'تومان';
?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- HERO SECTION -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-hero">
    <div class="ch-hero-bg">
        <div class="ch-hero-gradient"></div>
        <div class="ch-hero-pattern"></div>
        <div class="ch-hero-particles" id="heroParticles"></div>
        <!-- شکل‌های تزئینی -->
        <div class="ch-hero-shape ch-hero-shape-1"></div>
        <div class="ch-hero-shape ch-hero-shape-2"></div>
        <div class="ch-hero-shape ch-hero-shape-3"></div>
    </div>

    <div class="container">
        <div class="ch-hero-content">
            <div class="ch-hero-badge" data-animate="fadeInDown">
                <span class="material-icons">auto_awesome</span>
                پلتفرم حرفه‌ای کسب درآمد آنلاین
            </div>

            <h1 class="ch-hero-title" data-animate="fadeInUp">
                با <span class="ch-hero-highlight">چرتکه</span>
                <br>
                درآمد واقعی کسب کنید
            </h1>

            <p class="ch-hero-desc" data-animate="fadeInUp" data-delay="200">
                انجام تسک شبکه‌های اجتماعی • سرمایه‌گذاری هوشمند • قرعه‌کشی روزانه
                <br>
                تولید محتوای خلاقانه • معرفی دوستان • سفارش تبلیغات
            </p>

            <div class="ch-hero-actions" data-animate="fadeInUp" data-delay="400">
                <?php if ($isLoggedIn): ?>
                    <a href="<?= url('/dashboard') ?>" class="ch-btn ch-btn-white ch-btn-lg">
                        <span class="material-icons">dashboard</span>
                        ورود به داشبورد
                    </a>
                <?php else: ?>
                    <a href="<?= url('/register') ?>" class="ch-btn ch-btn-white ch-btn-lg">
                        <span class="material-icons">rocket_launch</span>
                        شروع رایگان
                    </a>
                    <a href="<?= url('/help') ?>" class="ch-btn ch-btn-ghost ch-btn-lg">
                        <span class="material-icons">play_circle</span>
                        نحوه کار
                    </a>
                <?php endif; ?>
            </div>

            <!-- تراست‌بار -->
            <div class="ch-hero-trust" data-animate="fadeInUp" data-delay="600">
                <div class="ch-trust-item">
                    <span class="material-icons">verified_user</span>
                    امنیت بالا
                </div>
                <div class="ch-trust-divider"></div>
                <div class="ch-trust-item">
                    <span class="material-icons">speed</span>
                    پرداخت سریع
                </div>
                <div class="ch-trust-divider"></div>
                <div class="ch-trust-item">
                    <span class="material-icons">support_agent</span>
                    پشتیبانی ۲۴/۷
                </div>
            </div>
        </div>

        <!-- آمار زنده -->
        <div class="ch-hero-stats" data-animate="fadeInUp" data-delay="800">
            <div class="ch-stat-card" data-target="<?= e((int)($stats->users ?? 0)) ?>">
                <div class="ch-stat-icon ch-stat-blue">
                    <span class="material-icons">groups</span>
                </div>
                <div class="ch-stat-info">
                    <span class="ch-stat-number">0</span>
                    <span class="ch-stat-label">کاربر فعال</span>
                </div>
            </div>
            <div class="ch-stat-card" data-target="<?= e((int)($stats->tasks ?? 0)) ?>">
                <div class="ch-stat-icon ch-stat-green">
                    <span class="material-icons">task_alt</span>
                </div>
                <div class="ch-stat-info">
                    <span class="ch-stat-number">0</span>
                    <span class="ch-stat-label">تسک انجام‌شده</span>
                </div>
            </div>
            <div class="ch-stat-card" data-target="<?= e((int)($stats->transactions ?? 0)) ?>">
                <div class="ch-stat-icon ch-stat-orange">
                    <span class="material-icons">receipt_long</span>
                </div>
                <div class="ch-stat-info">
                    <span class="ch-stat-number">0</span>
                    <span class="ch-stat-label">تراکنش موفق</span>
                </div>
            </div>
            <div class="ch-stat-card" data-target="<?= e((int)($stats->winners ?? 0)) ?>">
                <div class="ch-stat-icon ch-stat-purple">
                    <span class="material-icons">emoji_events</span>
                </div>
                <div class="ch-stat-info">
                    <span class="ch-stat-number">0</span>
                    <span class="ch-stat-label">برنده قرعه‌کشی</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- تبلیغات ویژه (اسلایدر بزرگ) -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-section ch-section-white" id="featured-ads">
    <div class="container">
        <div class="ch-section-header">
            <span class="ch-badge-pill ch-badge-warning">
                <span class="material-icons">campaign</span>
                تبلیغات ویژه
            </span>
            <h2 class="ch-section-title">کسب‌وکارهای منتخب</h2>
            <p class="ch-section-sub">بهترین فرصت‌ها و پیشنهادهای ویژه برای شما</p>
        </div>

        <?php if (!empty($banners)): ?>
            <div class="ch-slider-container">
                <div class="ch-slider" id="featuredSlider">
                    <?php foreach ($banners as $banner): ?>
                        <div class="ch-slide">
                            <div class="ch-slide-inner">
                                <div class="ch-slide-media">
                                    <?php if (!empty($banner->image_path)): ?>
                                        <img src="<?= asset($banner->image_path) ?>"
                                             alt="<?= e($banner->title ?? '') ?>"
                                             loading="lazy">
                                    <?php else: ?>
                                        <div class="ch-slide-placeholder">
                                            <span class="material-icons">campaign</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (isset($banner->price) && $banner->price == 0): ?>
                                        <span class="ch-slide-free-badge">🎁 رایگان</span>
                                    <?php endif; ?>
                                </div>
                                <div class="ch-slide-content">
                                    <h3><?= e($banner->title ?? 'تبلیغ ویژه') ?></h3>
                                    <?php if (!empty($banner->description)): ?>
                                        <p><?= e($banner->description) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($banner->link)): ?>
                                        <a href="<?= sanitize_url($banner->link) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="ch-btn ch-btn-primary"
                                           data-banner-id="<?= (int)($banner->id ?? 0) ?>">
                                            <span class="material-icons">open_in_new</span>
                                            مشاهده بیشتر
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (count($banners) > 1): ?>
                    <button class="ch-slider-arrow ch-slider-prev" id="sliderPrev">
                        <span class="material-icons">chevron_right</span>
                    </button>
                    <button class="ch-slider-arrow ch-slider-next" id="sliderNext">
                        <span class="material-icons">chevron_left</span>
                    </button>
                    <div class="ch-slider-dots" id="sliderDots"></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="ch-empty-state">
                <span class="material-icons">campaign</span>
                <p>به‌زودی تبلیغات ویژه اضافه خواهند شد</p>
            </div>
        <?php endif; ?>
    </div>
</section>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- روش‌های کسب درآمد -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-section ch-section-light" id="earning-methods">
    <div class="container">
        <div class="ch-section-header">
            <span class="ch-badge-pill">
                <span class="material-icons">monetization_on</span>
                راه‌های درآمدزایی
            </span>
            <h2 class="ch-section-title">۷ روش کسب درآمد واقعی</h2>
            <p class="ch-section-sub">متنوع‌ترین راه‌های کسب درآمد آنلاین در یک پلتفرم</p>
        </div>

        <div class="ch-earning-grid">
            <?php
            $earningCards = [
                [
                    'icon' => 'thumb_up',
                    'gradient' => 'linear-gradient(135deg, #e91e63, #f48fb1)',
                    'title' => 'تسک‌ها و تبلیغات',
                    'desc' => 'لایک، فالو، کامنت، بازدید ویدیو و عضویت در کانال‌ها',
                    'features' => ['اینستاگرام', 'یوتیوب', 'تلگرام', 'تیک‌تاک و توییتر'],
                    'link' => $isLoggedIn ? '/tasks' : '/register',
                    'btn' => $isLoggedIn ? 'شروع کنید' : 'ثبت‌نام کنید',
                ],
                [
                    'icon' => 'trending_up',
                    'gradient' => 'linear-gradient(135deg, #ff9800, #ffcc80)',
                    'title' => 'سرمایه‌گذاری',
                    'desc' => 'سرمایه‌گذاری روی طلا در فارکس و کسب سود هفتگی',
                    'features' => ['گزارش هفتگی', 'جزئیات ترید', 'بر اساس تتر', '⚠ احتمال سود و ضرر'],
                    'link' => $isLoggedIn ? '/investment' : '/register',
                    'btn' => $isLoggedIn ? 'شروع کنید' : 'ثبت‌نام کنید',
                ],
                [
                    'icon' => 'casino',
                    'gradient' => 'linear-gradient(135deg, #9c27b0, #ce93d8)',
                    'title' => 'قرعه‌کشی روزانه',
                    'desc' => 'هر روز شانس خود را امتحان کنید! سیستم عادلانه و ضد تقلب',
                    'features' => ['شرکت روزانه', 'بدون حذف کاربر', 'جوایز نقدی', 'سیستم وزن‌دهی'],
                    'link' => $isLoggedIn ? '/lottery' : '/register',
                    'btn' => $isLoggedIn ? 'شرکت کنید' : 'ثبت‌نام کنید',
                ],
                [
                    'icon' => 'videocam',
                    'gradient' => 'linear-gradient(135deg, #f44336, #ef9a9a)',
                    'title' => 'استعداد و محتوا',
                    'desc' => 'ویدیو بسازید، آپلود کنید و از بازدیدها درآمد کسب کنید',
                    'features' => ['آپلود در آپارات', 'تقسیم سود عادلانه', 'پرداخت ماهانه', 'رشد درآمد'],
                    'link' => $isLoggedIn ? '/content' : '/register',
                    'btn' => $isLoggedIn ? 'شروع کنید' : 'ثبت‌نام کنید',
                ],
                [
                    'icon' => 'people',
                    'gradient' => 'linear-gradient(135deg, #4caf50, #81c784)',
                    'title' => 'معرفی دوستان',
                    'desc' => 'لینک دعوت بفرستید و از فعالیت زیرمجموعه کمیسیون بگیرید',
                    'features' => ['لینک اختصاصی', 'کمیسیون مستقیم', 'آمار لحظه‌ای', 'بدون محدودیت'],
                    'link' => $isLoggedIn ? '/referral' : '/register',
                    'btn' => $isLoggedIn ? 'دعوت کنید' : 'ثبت‌نام کنید',
                ],
                [
                    'icon' => 'camera_alt',
                    'gradient' => 'linear-gradient(135deg, #2196f3, #64b5f6)',
                    'title' => 'سفارش استوری و پست',
                    'desc' => 'تبلیغ در صفحات بزرگ اینستاگرام با قیمت مناسب',
                    'features' => ['صفحات معتبر', 'قیمت‌گذاری آزاد', 'سیستم ضد تقلب', 'گزارش بازدید'],
                    'link' => $isLoggedIn ? '/story-orders' : '/register',
                    'btn' => $isLoggedIn ? 'سفارش دهید' : 'ثبت‌نام کنید',
                ],
                [
                    'icon' => 'search',
                    'gradient' => 'linear-gradient(135deg, #607d8b, #90a4ae)',
                    'title' => 'افزایش رتبه سئو',
                    'desc' => 'جستجوی کلمات کلیدی در گوگل و کمک به رشد سایت‌ها',
                    'features' => ['جستجوی واقعی', 'اسکرول هوشمند', 'رفتار طبیعی', 'ضد ربات'],
                    'link' => $isLoggedIn ? '/seo-tasks' : '/register',
                    'btn' => $isLoggedIn ? 'شروع کنید' : 'ثبت‌نام کنید',
                ],
            ];

            foreach ($earningCards as $i => $card):
            ?>
                <div class="ch-earn-card" style="animation-delay: <?= $i * 100 ?>ms">
                    <div class="ch-earn-card-top" style="background: <?= e($card['gradient']) ?>">
                        <span class="material-icons"><?= e($card['icon']) ?></span>
                    </div>
                    <div class="ch-earn-card-body">
                        <h3><?= e($card['title']) ?></h3>
                        <p><?= e($card['desc']) ?></p>
                        <ul>
                            <?php foreach ($card['features'] as $f): ?>
                                <li>
                                    <span class="material-icons"><?= str_starts_with($f, '⚠') ? 'warning' : 'check_circle' ?></span>
                                    <?= e(ltrim($f, '⚠ ')) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="ch-earn-card-footer">
                        <a href="<?= url($card['link']) ?>" class="ch-earn-btn">
                            <?= e($card['btn']) ?>
                            <span class="material-icons">arrow_back</span>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>


<!-- ═══════════════════════════════════════════════════════ -->
<!-- پیج‌های تبلیغ‌کننده -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-section ch-section-light" id="influencers">
    <div class="container">
        <div class="ch-section-header">
            <span class="ch-badge-pill ch-badge-pink">
                <span class="material-icons">auto_awesome</span>
                پیج‌های تبلیغ‌کننده
            </span>
            <h2 class="ch-section-title">صفحات آماده تبلیغ برای شما</h2>
            <p class="ch-section-sub">پیج‌های معتبر اینستاگرامی برای استوری و پست تبلیغاتی محصولات و خدمات شما</p>
        </div>

        <?php if (!empty($influencers)): ?>
            <div class="ch-inf-wrapper">
                <button class="ch-inf-arrow ch-inf-prev" id="infPrev">
                    <span class="material-icons">chevron_right</span>
                </button>

                <div class="ch-inf-scroll" id="infScroll">
                    <?php foreach ($influencers as $inf): ?>
                        <div class="ch-inf-card">
                            <div class="ch-inf-avatar-wrapper">
                                <div class="ch-inf-avatar-ring">
                                    <?php if (!empty($inf->avatar)): ?>
                                        <img src="<?= asset($inf->avatar) ?>"
                                             alt="<?= e($inf->instagram_username ?? '') ?>">
                                    <?php else: ?>
                                        <span class="material-icons">person</span>
                                    <?php endif; ?>
                                </div>
                                <div class="ch-inf-verified">
                                    <span class="material-icons">verified</span>
                                </div>
                            </div>
                            <h4 class="ch-inf-username">@<?= e($inf->instagram_username ?? '') ?></h4>
                            <div class="ch-inf-followers">
                                <span class="material-icons">groups</span>
                                <?= number_format((int)($inf->follower_count ?? 0)) ?> فالوور
                            </div>
                            <div class="ch-inf-price">
                                <?php
                                $price = (float)($inf->story_price ?? 0);
                                if ($currencyMode === 'usdt') {
                                    echo number_format($price, 2) . ' USDT';
                                } else {
                                    echo number_format($price) . ' تومان';
                                }
                                ?>
                                <small>/ استوری</small>
                            </div>
                            <a href="<?= url($isLoggedIn ? '/story-orders/create?influencer=' . (int)($inf->id ?? 0) : '/register') ?>"
                               class="ch-inf-btn">
                                <span class="material-icons">shopping_cart</span>
                                سفارش تبلیغ
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="ch-inf-arrow ch-inf-next" id="infNext">
                    <span class="material-icons">chevron_left</span>
                </button>
            </div>

            <div class="ch-center-cta">
                <a href="<?= url($isLoggedIn ? '/story-orders' : '/register') ?>" class="ch-btn ch-btn-outline">
                    مشاهده همه پیج‌ها
                    <span class="material-icons">arrow_back</span>
                </a>
            </div>
        <?php else: ?>
            <div class="ch-empty-state">
                <span class="material-icons">camera_alt</span>
                <p>به‌زودی پیج‌های تبلیغ‌کننده اضافه خواهند شد</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- مزایا -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-section ch-section-white" id="why">
    <div class="container">
        <div class="ch-section-header">
            <span class="ch-badge-pill ch-badge-success">
                <span class="material-icons">verified</span>
                مزایای ما
            </span>
            <h2 class="ch-section-title">چرا چرتکه را انتخاب کنید؟</h2>
        </div>

        <div class="ch-why-grid">
            <?php
            $whyCards = [
                ['icon' => 'security', 'color' => '#4caf50', 'title' => 'امنیت پیشرفته', 'desc' => 'رمزنگاری Argon2id، احراز هویت دو مرحله‌ای، سیستم ضد نفوذ و محافظت از حساب شما'],
                ['icon' => 'bolt', 'color' => '#2196f3', 'title' => 'پرداخت سریع', 'desc' => 'برداشت روزانه از طریق درگاه‌های بانکی معتبر و شبکه‌های ارز دیجیتال USDT'],
                ['icon' => 'credit_card', 'color' => '#ff9800', 'title' => 'درگاه‌های متنوع', 'desc' => 'پشتیبانی از زرین‌پال، آی‌دی‌پی، نکست‌پی و USDT روی شبکه‌های TRC20 و BEP20'],
                ['icon' => 'shield', 'color' => '#f44336', 'title' => 'ضد تقلب هوشمند', 'desc' => 'تحلیل رفتاری، اثرانگشت دستگاه، نظارت چندلایه و جلوگیری از هرگونه سوءاستفاده'],
                ['icon' => 'headset_mic', 'color' => '#9c27b0', 'title' => 'پشتیبانی حرفه‌ای', 'desc' => 'تیم پشتیبانی ۲۴ ساعته از طریق تیکت، چت آنلاین، تلگرام و واتساپ'],
                ['icon' => 'diversity_3', 'color' => '#009688', 'title' => 'جامعه بزرگ', 'desc' => 'هزاران کاربر فعال از سراسر ایران هر روز در چرتکه فعالیت و درآمدزایی می‌کنند'],
            ];
            foreach ($whyCards as $i => $w):
            ?>
                <div class="ch-why-card" style="animation-delay: <?= $i * 100 ?>ms">
                    <div class="ch-why-icon" style="background: <?= e($w['color']) ?>15; color: <?= e($w['color']) ?>">
                        <span class="material-icons"><?= e($w['icon']) ?></span>
                    </div>
                    <h3><?= e($w['title']) ?></h3>
                    <p><?= e($w['desc']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- نحوه کار -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-section ch-section-gradient" id="how-it-works">
    <div class="container">
        <div class="ch-section-header ch-header-white">
            <span class="ch-badge-pill ch-badge-white">
                <span class="material-icons">route</span>
                شروع کار
            </span>
            <h2 class="ch-section-title">فقط ۴ قدم تا درآمد واقعی</h2>
        </div>

        <div class="ch-steps">
            <div class="ch-step">
                <div class="ch-step-num">۱</div>
                <div class="ch-step-icon-wrap">
                    <span class="material-icons">person_add</span>
                </div>
                <h3>ثبت‌نام رایگان</h3>
                <p>فقط ۳۰ ثانیه! نام، ایمیل و رمز عبور</p>
            </div>
            <div class="ch-step-line"></div>
            <div class="ch-step">
                <div class="ch-step-num">۲</div>
                <div class="ch-step-icon-wrap">
                    <span class="material-icons">verified_user</span>
                </div>
                <h3>احراز هویت</h3>
                <p>آپلود کارت ملی و سلفی برای تأیید</p>
            </div>
            <div class="ch-step-line"></div>
            <div class="ch-step">
                <div class="ch-step-num">۳</div>
                <div class="ch-step-icon-wrap">
                    <span class="material-icons">work</span>
                </div>
                <h3>انجام کار</h3>
                <p>تسک، سرمایه‌گذاری یا تولید محتوا</p>
            </div>
            <div class="ch-step-line"></div>
            <div class="ch-step">
                <div class="ch-step-num">۴</div>
                <div class="ch-step-icon-wrap">
                    <span class="material-icons">account_balance_wallet</span>
                </div>
                <h3>برداشت وجه</h3>
                <p>انتقال به حساب بانکی یا ارز دیجیتال</p>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- برندگان قرعه‌کشی -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-section ch-section-white" id="winners">
    <div class="container">
        <div class="ch-section-header">
            <span class="ch-badge-pill ch-badge-gold">
                <span class="material-icons">emoji_events</span>
                برندگان
            </span>
            <h2 class="ch-section-title">آخرین برندگان قرعه‌کشی</h2>
        </div>

        <?php if (!empty($winners)): ?>
            <div class="ch-winners-list">
                <?php
                $medals = ['🏆', '🥈', '🥉', '🏅', '🎖️'];
                foreach ($winners as $i => $w):
                    $name = $w->full_name ?? 'کاربر';
                    $parts = explode(' ', $name);
                    $masked = '';
                    foreach ($parts as $p) {
                        $masked .= mb_substr($p, 0, 1) . '.*** ';
                    }
                ?>
                    <div class="ch-winner-row <?= $i === 0 ? 'ch-winner-gold' : '' ?>">
                        <div class="ch-winner-medal"><?= $medals[$i] ?? '🎖️' ?></div>
                        <div class="ch-winner-info">
                            <strong><?= e(trim($masked)) ?></strong>
                            <small><?= to_jalali($w->created_at ?? date('Y-m-d'), 'Y/m/d') ?></small>
                        </div>
                        <div class="ch-winner-amount">
                            <?php
                            $prize = (float)($w->prize_amount ?? 0);
                            if ($currencyMode === 'usdt') {
                                echo number_format($prize, 2) . ' <small>USDT</small>';
                            } else {
                                echo number_format($prize) . ' <small>تومان</small>';
                            }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ch-center-cta">
                <a href="<?= url($isLoggedIn ? '/lottery' : '/register') ?>" class="ch-btn ch-btn-gradient ch-btn-lg">
                    <span class="material-icons">casino</span>
                    شرکت در قرعه‌کشی امروز
                </a>
            </div>
        <?php else: ?>
            <div class="ch-empty-state">
                <span class="material-icons">emoji_events</span>
                <p>هنوز قرعه‌کشی برگزار نشده. به‌زودی!</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- سطح‌بندی -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-section ch-section-light" id="tiers">
    <div class="container">
        <div class="ch-section-header">
            <span class="ch-badge-pill">
                <span class="material-icons">military_tech</span>
                سطح‌بندی کاربران
            </span>
            <h2 class="ch-section-title">هر چه فعال‌تر، درآمد بیشتر!</h2>
        </div>

        <div class="ch-tiers-grid">
            <div class="ch-tier ch-tier-silver">
                <div class="ch-tier-emoji">🥈</div>
                <h3>Silver</h3>
                <p class="ch-tier-sub">سطح پایه</p>
                <ul>
                    <li><span class="material-icons">check</span> دسترسی به تسک‌ها</li>
                    <li><span class="material-icons">check</span> درآمد پایه</li>
                    <li><span class="material-icons">check</span> شرکت در قرعه‌کشی</li>
                    <li><span class="material-icons">check</span> پشتیبانی عادی</li>
                </ul>
                <div class="ch-tier-price">رایگان</div>
            </div>

            <div class="ch-tier ch-tier-gold ch-tier-featured">
                <div class="ch-tier-popular">⭐ محبوب‌ترین</div>
                <div class="ch-tier-emoji">🥇</div>
                <h3>Gold</h3>
                <p class="ch-tier-sub">پاداش بیشتر</p>
                <ul>
                    <li><span class="material-icons">check</span> تمام امکانات Silver</li>
                    <li><span class="material-icons">check</span> درصد درآمد بالاتر</li>
                    <li><span class="material-icons">check</span> تسک‌های ویژه</li>
                    <li><span class="material-icons">check</span> پشتیبانی اولویت‌دار</li>
                    <li><span class="material-icons">check</span> کمیسیون بیشتر</li>
                </ul>
                <div class="ch-tier-price">فعالیت مداوم یا خرید</div>
            </div>

            <div class="ch-tier ch-tier-vip">
                <div class="ch-tier-emoji">💎</div>
                <h3>VIP</h3>
                <p class="ch-tier-sub">حداکثر درآمد</p>
                <ul>
                    <li><span class="material-icons">check</span> تمام امکانات Gold</li>
                    <li><span class="material-icons">check</span> بالاترین درصد</li>
                    <li><span class="material-icons">check</span> دسترسی زودتر</li>
                    <li><span class="material-icons">check</span> پشتیبانی اختصاصی</li>
                    <li><span class="material-icons">check</span> بدون محدودیت</li>
                </ul>
                <div class="ch-tier-price">فعالیت حرفه‌ای یا خرید</div>
            </div>
        </div>

        <p class="ch-tier-warning">
            <span class="material-icons">info</span>
            اگر در ماه بیش از ۳ روز به پنل سر نزنید، سطح بر اساس فعالیت ریست می‌شود.
        </p>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- سوالات متداول -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-section ch-section-white" id="faq">
    <div class="container">
        <div class="ch-section-header">
            <span class="ch-badge-pill ch-badge-info">
                <span class="material-icons">help_outline</span>
                سوالات متداول
            </span>
            <h2 class="ch-section-title">پاسخ سوالات شما</h2>
        </div>

        <div class="ch-faq-list">
            <?php foreach ($faqs as $i => $faq): ?>
                <div class="ch-faq-item">
                    <button class="ch-faq-q" data-faq="<?= e($i) ?>">
                        <span class="ch-faq-q-icon"><span class="material-icons">help</span></span>
                        <span class="ch-faq-q-text"><?= e($faq['question']) ?></span>
                        <span class="material-icons ch-faq-arrow">expand_more</span>
                    </button>
                    <div class="ch-faq-a" id="faq-a-<?= e($i) ?>">
                        <p><?= e($faq['answer']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- CTA نهایی -->
<!-- ═══════════════════════════════════════════════════════ -->
<section class="ch-cta-section">
    <div class="container">
        <div class="ch-cta-box">
            <div class="ch-cta-bg-shapes">
                <div class="ch-cta-shape-1"></div>
                <div class="ch-cta-shape-2"></div>
            </div>
            <div class="ch-cta-content">
                <span class="material-icons ch-cta-icon">rocket_launch</span>
                <h2>همین الان به چرتکه بپیوندید!</h2>
                <p>بیش از <strong><?= number_format((int)($stats->users ?? 0)) ?></strong> کاربر فعال به ما اعتماد کرده‌اند</p>
                <?php if ($isLoggedIn): ?>
                    <a href="<?= url('/dashboard') ?>" class="ch-btn ch-btn-white ch-btn-xl">
                        <span class="material-icons">dashboard</span>
                        ورود به داشبورد
                    </a>
                <?php else: ?>
                    <a href="<?= url('/register') ?>" class="ch-btn ch-btn-white ch-btn-xl">
                        <span class="material-icons">person_add</span>
                        ثبت‌نام رایگان
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
$extra_css = '<link rel="stylesheet" href="' . asset('assets/css/home.css') . '">';
$extra_js = '<script src="' . asset('assets/js/home.js') . '"></script>';
include view_path('layouts.guest');
?>