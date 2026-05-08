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
                <a href="<?= url('/settings/security') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-shield-alt me-2"></i> امنیتی
                </a>
                <a href="<?= url('/settings/notifications') ?>" class="list-group-item list-group-item-action active">
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
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h4 class="m-0"><?= h($title) ?></h4>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= url('/settings/notifications/update') ?>">
                        <?= csrf_field() ?>

                        <!-- Email Notifications -->
                        <div class="mb-4">
                            <h5 class="mb-3">اعلان‌های ایمیل</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="email_notifications" 
                                       name="email_notifications" <?= $settings['email_notifications'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="email_notifications">
                                    اعلان‌های عمومی ایمیلی
                                </label>
                                <small class="text-muted d-block">دریافت اعلان‌های مهم از طریق ایمیل</small>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="marketing_emails" 
                                       name="marketing_emails" <?= $settings['marketing_emails'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="marketing_emails">
                                    ایمیل‌های تبلیغاتی
                                </label>
                                <small class="text-muted d-block">دریافت اخبار و پیشنهادات تبلیغاتی</small>
                            </div>
                        </div>

                        <!-- Push Notifications -->
                        <div class="mb-4">
                            <h5 class="mb-3">اعلان‌های پوش</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="push_notifications" 
                                       name="push_notifications" <?= $settings['push_notifications'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="push_notifications">
                                    اعلان‌های پوش
                                </label>
                                <small class="text-muted d-block">دریافت اعلان‌های فوری در دستگاه‌های شما</small>
                            </div>
                        </div>

                        <!-- SMS Notifications -->
                        <div class="mb-4">
                            <h5 class="mb-3">اعلان‌های پیامکی</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sms_notifications" 
                                       name="sms_notifications" <?= $settings['sms_notifications'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sms_notifications">
                                    اعلان‌های پیامکی
                                </label>
                                <small class="text-muted d-block">دریافت اعلان‌های حساس از طریق پیامک</small>
                            </div>
                        </div>

                        <!-- Note -->
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>نکته:</strong> برای اعلان‌های امنیتی مهم (نظیر تلاش ورود)، دریافت اعلان اجباری است.
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
        </div>
    </div>
</div>

<?php view('layouts.footer') ?>
