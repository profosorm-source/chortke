<?php
$title = 'راهنمای سایت';
ob_start();
?>

<div class="static-page-hero">
    <div class="container">
        <h1><span class="material-icons">help_center</span> راهنمای جامع چرتکه</h1>
        <p>همه چیز درباره نحوه استفاده از امکانات سایت</p>
    </div>
</div>

<div class="container py-5">
    <div class="row g-4">

        <!-- فهرست کناری -->
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc-sticky">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-3">
                        <div class="fw-700 fs-13 mb-3 text-muted">بخش‌های راهنما</div>
                        <nav class="help-nav d-flex flex-column gap-1">
                            <a href="#help-start"   class="help-nav-item"><span class="material-icons">rocket_launch</span> شروع سریع</a>
                            <a href="#help-wallet"  class="help-nav-item"><span class="material-icons">account_balance_wallet</span> کیف پول</a>
                            <a href="#help-tasks"   class="help-nav-item"><span class="material-icons">task_alt</span> تسک‌ها</a>
                            <a href="#help-ads"     class="help-nav-item"><span class="material-icons">campaign</span> تبلیغات</a>
                            <a href="#help-invest"  class="help-nav-item"><span class="material-icons">trending_up</span> سرمایه‌گذاری</a>
                            <a href="#help-kyc"     class="help-nav-item"><span class="material-icons">verified_user</span> احراز هویت</a>
                            <a href="#help-referral"class="help-nav-item"><span class="material-icons">group_add</span> معرفی دوستان</a>
                            <a href="#help-support" class="help-nav-item"><span class="material-icons">support_agent</span> پشتیبانی</a>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <!-- محتوای اصلی -->
        <div class="col-lg-9">

            <!-- شروع سریع -->
            <div id="help-start" class="help-section">
                <div class="help-section-header">
                    <span class="material-icons">rocket_launch</span>
                    <h4>شروع سریع</h4>
                </div>
                <div class="help-step">
                    <div class="help-step-num">۱</div>
                    <div class="help-step-body">
                        <h6>ثبت‌نام کن</h6>
                        <p>با ایمیل یا شماره موبایل ثبت‌نام کن. تأیید ایمیل برای فعال‌سازی حساب الزامی است.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num">۲</div>
                    <div class="help-step-body">
                        <h6>پروفایل را تکمیل کن</h6>
                        <p>نام، عکس پروفایل و اطلاعات پایه را وارد کن. این کار امتیاز اولیه برایت به همراه دارد.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num">۳</div>
                    <div class="help-step-body">
                        <h6>احراز هویت انجام بده</h6>
                        <p>برای برداشت وجه و دسترسی به همه امکانات، KYC را تکمیل کن.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num">۴</div>
                    <div class="help-step-body">
                        <h6>شروع به کسب درآمد کن</h6>
                        <p>تسک‌ها را انجام بده، در تبلیغات شرکت کن یا سرمایه‌گذاری کن!</p>
                    </div>
                </div>
            </div>

            <!-- کیف پول -->
            <div id="help-wallet" class="help-section">
                <div class="help-section-header">
                    <span class="material-icons">account_balance_wallet</span>
                    <h4>کیف پول و مالی</h4>
                </div>
                <p>کیف پول چرتکه دو بخش تومانی و تتری دارد:</p>

                <h5 class="mt-3 mb-2">افزایش موجودی:</h5>
                <div class="help-step">
                    <div class="help-step-num" style="background:#4caf50">💳</div>
                    <div class="help-step-body">
                        <h6>درگاه آنلاین</h6>
                        <p>از طریق ZarinPal، NextPay، IDPay یا DgPay. فوری و خودکار.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num" style="background:#2196f3">🏦</div>
                    <div class="help-step-body">
                        <h6>کارت به کارت (دستی)</h6>
                        <p>برای کاربران تأیید هویت‌شده. پس از واریز، شماره پیگیری را در سایت وارد کن تا مدیریت تأیید کند.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num" style="background:#ff9800">₮</div>
                    <div class="help-step-body">
                        <h6>واریز تتر (USDT)</h6>
                        <p>روی شبکه TRX یا BNB20. هش تراکنش را وارد کن تا سیستم تأیید کند.</p>
                    </div>
                </div>

                <div class="help-tip">
                    <strong>نکته:</strong> در روز فقط یک درخواست واریز دستی می‌توانی ثبت کنی. اگر درخواست در انتظار داری، باید تکلیف آن مشخص شود.
                </div>

                <h5 class="mt-3 mb-2">برداشت وجه:</h5>
                <ul>
                    <li>حداکثر یک بار در روز</li>
                    <li>باید کارت بانکی تأییدشده داشته باشی</li>
                    <li>حداقل مبلغ برداشت طبق تعرفه سایت</li>
                    <li>تا ۴۸ ساعت کاری پردازش می‌شود</li>
                </ul>
            </div>

            <!-- تسک‌ها -->
            <div id="help-tasks" class="help-section">
                <div class="help-section-header">
                    <span class="material-icons">task_alt</span>
                    <h4>تسک‌ها</h4>
                </div>
                <p>تسک‌ها ساده‌ترین راه کسب درآمد در چرتکه هستند:</p>
                <ul>
                    <li><strong>تسک‌های روزانه:</strong> لایک، فالو، کامنت، نصب اپ، بازدید ویدیو</li>
                    <li><strong>تسک سفارشی:</strong> فعالیت‌های خاص با درآمد بیشتر</li>
                    <li><strong>SEO Boost:</strong> جستجوی کلمه کلیدی و بازدید از سایت</li>
                </ul>
                <div class="help-step">
                    <div class="help-step-num">①</div>
                    <div class="help-step-body">
                        <h6>تسک را انتخاب کن</h6>
                        <p>از لیست تسک‌های فعال یکی را انتخاب کن و دستورالعمل را بخوان.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num">②</div>
                    <div class="help-step-body">
                        <h6>انجام بده و مدرک بگیر</h6>
                        <p>تسک را انجام بده و سکرین‌شات یا ویدیو بگیر.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num">③</div>
                    <div class="help-step-body">
                        <h6>مدرک را آپلود کن</h6>
                        <p>در صفحه تسک، مدرک را آپلود کن. پس از بررسی درآمدت تأیید می‌شود.</p>
                    </div>
                </div>
                <div class="help-tip">
                    <strong>هشدار:</strong> ارسال مدرک جعلی منجر به حذف حساب و مسدود شدن موجودی می‌شود.
                </div>
            </div>

            <!-- احراز هویت -->
            <div id="help-kyc" class="help-section">
                <div class="help-section-header">
                    <span class="material-icons">verified_user</span>
                    <h4>احراز هویت (KYC)</h4>
                </div>
                <p>احراز هویت برای برداشت وجه و واریز دستی الزامی است:</p>
                <div class="help-step">
                    <div class="help-step-num">۱</div>
                    <div class="help-step-body">
                        <h6>عکس کارت ملی</h6>
                        <p>عکس واضح از پشت و روی کارت ملی آپلود کن.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num">۲</div>
                    <div class="help-step-body">
                        <h6>سلفی با دست‌نوشته</h6>
                        <p>یک سلفی با کارت ملی و یک کاغذ که روی آن نام سایت و تاریخ نوشته‌ای بگیر.</p>
                    </div>
                </div>
                <div class="help-step">
                    <div class="help-step-num">۳</div>
                    <div class="help-step-body">
                        <h6>انتظار تأیید</h6>
                        <p>تیم ما ظرف ۱ تا ۳ روز کاری مدارک را بررسی می‌کند.</p>
                    </div>
                </div>
            </div>

            <!-- معرفی دوستان -->
            <div id="help-referral" class="help-section">
                <div class="help-section-header">
                    <span class="material-icons">group_add</span>
                    <h4>سیستم معرفی دوستان</h4>
                </div>
                <p>با معرفی دوستان به چرتکه از فعالیت‌های آن‌ها کمیسیون بگیر:</p>
                <ul>
                    <li>لینک اختصاصی خودت را از بخش «دعوت از دوستان» کپی کن</li>
                    <li>با هر ثبت‌نام موفق از لینک تو، کمیسیون تعلق می‌گیرد</li>
                    <li>کمیسیون از درآمد تسک‌ها و سایر فعالیت‌های فرد معرفی‌شده محاسبه می‌شود</li>
                    <li>نرخ کمیسیون در پنل مدیریت قابل تنظیم است</li>
                </ul>
            </div>

            <!-- پشتیبانی -->
            <div id="help-support" class="help-section">
                <div class="help-section-header">
                    <span class="material-icons">support_agent</span>
                    <h4>پشتیبانی</h4>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body text-center p-4">
                                <span class="material-icons text-primary mb-2" style="font-size:40px">confirmation_number</span>
                                <h6>سیستم تیکت</h6>
                                <p class="text-muted fs-13">برای مشکلات مالی، فنی یا اعتراض به تسک تیکت ارسال کن. پاسخ در کمتر از ۲۴ ساعت کاری.</p>
                                <a href="<?= url('/tickets/create') ?>" class="btn btn-add btn-sm">ارسال تیکت</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body text-center p-4">
                                <span class="material-icons mb-2" style="font-size:40px;color:#0088cc">send</span>
                                <h6>پشتیبانی تلگرام</h6>
                                <p class="text-muted fs-13">برای سوالات فوری از طریق تلگرام با ما در ارتباط باش.</p>
                                <a href="https://t.me/<?= e(setting('telegram_support','chortke_support')) ?>" class="btn btn-outline-secondary btn-sm" target="_blank">پیام در تلگرام</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
// Highlight active help nav item on scroll
document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('.help-section');
    const navItems = document.querySelectorAll('.help-nav-item');
    
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                navItems.forEach(item => item.classList.remove('active'));
                const id = entry.target.id;
                const active = document.querySelector(`.help-nav-item[href="#${id}"]`);
                if (active) active.classList.add('active');
            }
        });
    }, { threshold: 0.4 });
    
    sections.forEach(s => observer.observe(s));
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>
