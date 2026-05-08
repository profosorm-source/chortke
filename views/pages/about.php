<?php
$title = 'درباره چرتکه';
ob_start();
?>

<div class="static-page-hero">
    <div class="container">
        <h1><span class="material-icons">info</span> درباره چرتکه</h1>
        <p>پلتفرم جامع کسب درآمد آنلاین ایرانی</p>
    </div>
</div>

<div class="container py-5">
    <!-- معرفی -->
    <div class="row justify-content-center mb-5">
        <div class="col-lg-8 text-center">
            <img src="<?= asset('images/logo-dark.png') ?>" alt="چرتکه" style="height:80px;" class="mb-4">
            <h2 class="mb-3">چرتکه چیست؟</h2>
            <p class="lead text-muted" style="line-height:2">
                چرتکه یک پلتفرم نوین برای کسب درآمد آنلاین است. با انجام تسک، راه‌اندازی کمپین تبلیغاتی،
                سرمایه‌گذاری تتری، و فعالیت در شبکه‌های اجتماعی می‌توانید درآمد واقعی داشته باشید.
            </p>
        </div>
    </div>

    <!-- ویژگی‌ها -->
    <div class="row g-4 mb-5">
        <?php
        $features = [
            ['material-icons task_alt','#4caf50','انجام تسک','از لایک اینستاگرام تا نصب اپ. تسک‌های متنوع با پرداخت فوری.'],
            ['material-icons campaign','#2196f3','تبلیغات هدفمند','کمپین تبلیغاتی بسازید و هزاران کاربر واقعی جذب کنید.'],
            ['material-icons trending_up','#ff9800','سرمایه‌گذاری تتری','با USDT سرمایه‌گذاری کن و از سود روزانه بهره ببر.'],
            ['material-icons auto_stories','#9c27b0','استوری اینفلوئنسر','محتوای خود را به هزاران دنبال‌کننده برسانید.'],
            ['material-icons group_add','#e91e63','سیستم معرفی','دوستانت را دعوت کن و از فعالیت آن‌ها کمیسیون بگیر.'],
            ['material-icons security','#00bcd4','امنیت بالا','احراز هویت ۲ مرحله‌ای، رمزگذاری SSL و نظارت ۲۴ ساعته.'],
        ];
        foreach ($features as [$icon_class, $color, $title_f, $desc]):
            [$type, $icon] = explode(' ', $icon_class);
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="about-feature-card">
                <div class="icon-wrap" style="background:<?= e($color) ?>22">
                    <span class="material-icons" style="color:<?= e($color) ?>;font-size:28px"><?= e($icon) ?></span>
                </div>
                <h5><?= e($title_f) ?></h5>
                <p><?= e($desc) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- آمارها -->
    <div class="card border-0 shadow-sm mb-5" style="background:linear-gradient(135deg,#e3f2fd,#f0f8ff)">
        <div class="card-body py-5">
            <div class="row text-center">
                <?php
                $stats = [
                    ['۵۰,۰۰۰+', 'کاربر فعال'],
                    ['۱,۰۰۰,۰۰۰+', 'تسک انجام شده'],
                    ['۲۰۰+', 'تبلیغ‌دهنده'],
                    ['۹۸٪', 'رضایت کاربران'],
                ];
                foreach ($stats as [$num, $label]): ?>
                <div class="col-6 col-md-3">
                    <div class="about-stat">
                        <div class="about-stat-num"><?= e($num) ?></div>
                        <div class="about-stat-label"><?= e($label) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ارزش‌ها -->
    <div class="row justify-content-center mb-5">
        <div class="col-lg-10">
            <h3 class="text-center mb-4">ارزش‌های ما</h3>
            <div class="static-content">
                <p>ما در چرتکه باور داریم که هر ایرانی شایسته دسترسی به فرصت‌های واقعی کسب درآمد دیجیتال است.</p>
                <ul>
                    <li><strong>شفافیت:</strong> همه قوانین، نرخ‌ها و فرآیندها به صورت کامل و روشن اعلام می‌شوند</li>
                    <li><strong>سرعت:</strong> پرداخت‌ها در کمترین زمان ممکن پردازش می‌شوند</li>
                    <li><strong>امنیت:</strong> اطلاعات کاربران با بالاترین استانداردها محافظت می‌شود</li>
                    <li><strong>پشتیبانی:</strong> تیم ما در ساعات اداری آماده پاسخ‌گویی است</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- CTA -->
    <div class="text-center">
        <a href="<?= url('/register') ?>" class="btn btn-add btn-lg px-5">
            <span class="material-icons">rocket_launch</span>
            همین الان شروع کن
        </a>
        <a href="<?= url('/contact') ?>" class="btn btn-outline-secondary btn-lg px-5 ms-2">
            <span class="material-icons">contact_support</span>
            تماس با ما
        </a>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>
