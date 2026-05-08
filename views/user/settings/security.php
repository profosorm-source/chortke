<?php view('layouts.header', ['title' => $title]) ?>

<div class="container py-4">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <div class="list-group">
                <a href="<?= url('/settings/general') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-cog me-2"></i> تنظیمات عمومی
                </a>
                <a href="<?= url('/settings/privacy') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-lock me-2"></i> حریم خصوصی
                </a>
                <a href="<?= url('/settings/security') ?>" class="list-group-item list-group-item-action active">
                    <i class="fas fa-shield-alt me-2"></i> امنیتی
                </a>
                <a href="<?= url('/settings/notifications') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-bell me-2"></i> اعلان‌ها
                </a>
                <a href="<?= url('/settings/data-export') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-download me-2"></i> صادر کردن داده‌ها
                </a>
                <a href="<?= url('/settings/account-deletion') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-trash me-2"></i> حذف حساب
                </a>
            </div>
        </div>

        <!-- Content -->
        <div class="col-md-9">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="m-0">تنظیمات امنیتی</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= url('/settings/security/update') ?>">
                        <?= csrf_field() ?>

                        <!-- Session Timeout -->
                        <div class="mb-4">
                            <h5 class="mb-3">جلسه و خروج خودکار</h5>
                            <div class="mb-3">
                                <label for="session_timeout" class="form-label">زمان انتظار خروج خودکار (دقیقه)</label>
                                <input type="number" id="session_timeout" name="session_timeout" 
                                       class="form-control" min="5" max="480"
                                       value="<?= (int)$settings['session_timeout'] ?>">
                                <small class="text-muted">اگر برای این مدت غیرفعال باشید، خروج خودکار خواهید شد</small>
                            </div>
                        </div>

                        <!-- Alerts -->
                        <div class="mb-4">
                            <h5 class="mb-3">هشدارهای امنیتی</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="login_alerts" 
                                       name="login_alerts" <?= $settings['login_alerts'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="login_alerts">
                                    هشدار برای ورود جدید
                                </label>
                                <small class="text-muted d-block">دریافت اعلان هنگام ورود از دستگاه جدید</small>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="suspicious_activity_alerts" 
                                       name="suspicious_activity_alerts" <?= $settings['suspicious_activity_alerts'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="suspicious_activity_alerts">
                                    هشدار برای فعالیت مریب
                                </label>
                                <small class="text-muted d-block">دریافت اعلان برای رفتار غیرعادی</small>
                            </div>
                        </div>

                        <!-- Active Sessions -->
                        <div class="mb-4">
                            <h5 class="mb-3">جلسات فعال</h5>
                            <p class="text-muted">برای مدیریت جلسات فعال خود به <a href="<?= url('/sessions') ?>">صفحه جلسات</a> بروید</p>
                        </div>

                        <!-- Password Change -->
                        <div class="mb-4">
                            <h5 class="mb-3">تغییر رمزعبور</h5>
                            <p class="text-muted">برای تغییر رمزعبور به <a href="<?= url('/profile') ?>">صفحه پروفایل</a> بروید</p>
                        </div>

                        <!-- Two-Factor Authentication -->
                        <div class="mb-4">
                            <h5 class="mb-3">احراز هویت دو مرحله‌ای</h5>
                            <p class="text-muted">برای فعال‌سازی احراز هویت دو مرحله‌ای به <a href="<?= url('/two-factor') ?>">صفحه احراز هویت</a> بروید</p>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> ذخیره تنظیمات
                            </button>
                            <a href="<?= url('/dashboard') ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i> انصراف
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Security Tips -->
            <div class="card shadow-sm">
                <div class="card-header bg-warning bg-opacity-10">
                    <h5 class="m-0"><i class="fas fa-lightbulb me-2"></i> نکات امنیتی</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            رمزعبور خود را حداقل هر سه ماه یک بار تغییر دهید
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            از رمزعبور قوی استفاده کنید (حروف، اعداد، نمادها)
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            احراز هویت دو مرحله‌ای را فعال کنید
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            جلسات فعال غیرضروری را ببندید
                        </li>
                        <li>
                            <i class="fas fa-check-circle text-success me-2"></i>
                            از وای‌فای عمومی برای کارهای حساس استفاده نکنید
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php view('layouts.footer') ?>
