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
                <a href="<?= url('/settings/notifications') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-bell me-2"></i> اعلان‌ها
                </a>
                <a href="<?= url('/settings/data-export') ?>" class="list-group-item list-group-item-action active">
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
                    <p class="mb-4">
                        داده‌های شخصی خود را صادر کنید. فایل شامل اطلاعات پروفایل، تراکنش‌ها و سایر اطلاعات مرتبط شما خواهد بود.
                    </p>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card border-light">
                                <div class="card-body text-center">
                                    <h5 class="card-title">JSON</h5>
                                    <p class="card-text text-muted">قالب ساختارمند برای تجزیه آسان</p>
                                    <form method="POST" action="<?= url('/data-export/request') ?>" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="format" value="json">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-download me-1"></i> صادر کردن
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-light">
                                <div class="card-body text-center">
                                    <h5 class="card-title">CSV</h5>
                                    <p class="card-text text-muted">قالب صفحه‌گسترده برای Excel</p>
                                    <form method="POST" action="<?= url('/data-export/request') ?>" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="format" value="csv">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-download me-1"></i> صادر کردن
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>درخواست شما پردازش شده و برای شما ایمیل خواهد شد.</strong>
                        فایل صادرشده برای ۳۰ روز در سرور محفوظ خواهد ماند.
                    </div>

                    <div class="text-end">
                        <a href="<?= url('/dashboard') ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i> بازگشت
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php view('layouts.footer') ?>
