<?php
$title = 'تماس با ما';
ob_start();
?>

<div class="static-page-hero">
    <div class="container">
        <h1><span class="material-icons">contact_support</span> تماس با ما</h1>
        <p>هر سوالی داری اینجاییم</p>
    </div>
</div>

<div class="container py-5">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="contact-info-card">
                <h4 class="mb-4">راه‌های ارتباطی</h4>
                <div class="contact-item">
                    <div class="contact-icon"><span class="material-icons">email</span></div>
                    <div>
                        <div class="contact-label">ایمیل</div>
                        <a href="mailto:<?= e(setting('contact_email')) ?>" class="contact-value"><?= e(setting('contact_email','support@chortke.ir')) ?></a>
                    </div>
                </div>
                <div class="contact-item">
                    <div class="contact-icon"><span class="material-icons">phone</span></div>
                    <div>
                        <div class="contact-label">تلفن</div>
                        <a href="tel:<?= e(setting('contact_phone')) ?>" class="contact-value"><?= e(setting('contact_phone','021-XXXXXXXX')) ?></a>
                    </div>
                </div>
                <div class="contact-item">
                    <div class="contact-icon"><span class="material-icons">send</span></div>
                    <div>
                        <div class="contact-label">تلگرام</div>
                        <a href="https://t.me/<?= e(setting('telegram_support')) ?>" class="contact-value" target="_blank">@<?= e(setting('telegram_support','chortke_support')) ?></a>
                    </div>
                </div>
                <div class="contact-hours">
                    <div class="contact-icon"><span class="material-icons">schedule</span></div>
                    <div>
                        <div class="contact-label">ساعت پاسخگویی</div>
                        <div class="contact-value">شنبه تا چهارشنبه ۹ تا ۱۸</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h4 class="mb-4">ارسال پیام مستقیم</h4>
                    <form method="POST" action="<?= url('/contact/send') ?>">
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">نام و نام خانوادگی <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required placeholder="علی احمدی">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ایمیل <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" required placeholder="example@email.com">
                            </div>
                            <div class="col-12">
                                <label class="form-label">موضوع <span class="text-danger">*</span></label>
                                <select name="subject" class="form-select" required>
                                    <option value="">انتخاب کنید...</option>
                                    <option value="support">پشتیبانی فنی</option>
                                    <option value="financial">مسائل مالی</option>
                                    <option value="account">مشکل حساب کاربری</option>
                                    <option value="suggestions">پیشنهادات و انتقادات</option>
                                    <option value="complaint">شکایت</option>
                                    <option value="cooperation">درخواست همکاری</option>
                                    <option value="other">سایر موارد</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">پیام <span class="text-danger">*</span></label>
                                <textarea name="message" class="form-control" rows="6" required placeholder="پیام خود را با جزئیات بنویسید..."></textarea>
                            </div>
                            <?php if (function_exists('captcha_field')): ?><div class="col-12"><?= captcha_field() ?></div><?php endif; ?>
                            <div class="col-12">
                                <button type="submit" class="btn btn-add btn-lg">
                                    <span class="material-icons">send</span> ارسال پیام
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>
