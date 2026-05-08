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
                <a href="<?= url('/settings/data-export') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-download me-2"></i> صادر کردن داده‌ها
                </a>
                <a href="<?= url('/settings/account-deletion') ?>" class="list-group-item list-group-item-action active">
                    <i class="fas fa-trash me-2"></i> حذف حساب
                </a>
            </div>
        </div>

        <!-- Content -->
        <div class="col-md-9">
            <div class="card shadow-sm border-danger">
                <div class="card-header bg-danger bg-opacity-10">
                    <h4 class="m-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= h($title) ?></h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-warning me-2"></i>
                        <strong>هشدار:</strong> این عمل نمی‌تواند واگردانی شود! حسابتان برای همیشه حذف خواهد شد.
                    </div>

                    <p>اگر مطمئن هستید که می‌خواهید حسابتان را حذف کنید، لطفاً رمزعبور خود را وارد کنید:</p>

                    <form method="POST" action="<?= url('/settings/account-deletion/request') ?>">
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="password" class="form-label">رمزعبور</label>
                            <input type="password" id="password" name="password" class="form-control" required>
                            <small class="text-muted">برای تایید هویتتان رمزعبور وارد کنید</small>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" id="confirm" class="form-check-input" required>
                            <label class="form-check-label" for="confirm">
                                من متوجه‌ام که حسابم <strong>برای همیشه</strong> حذف خواهد شد
                            </label>
                        </div>

                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i> درخواست حذف حساب
                        </button>
                        <a href="<?= url('/dashboard') ?>" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-times me-2"></i> انصراف
                        </a>
                    </form>

                    <div class="mt-4 p-3 bg-light rounded">
                        <h5>اطلاعات مهم:</h5>
                        <ul class="mb-0">
                            <li>حسابتان برای ۷ روز به وضعیت "در انتظار حذف" درمی‌آید</li>
                            <li>در این مدت می‌توانید درخواست را لغو کنید</li>
                            <li>پس از ۷ روز، حسابتان برای همیشه حذف خواهد شد</li>
                            <li>تمام داده‌های شخصی شامل تراکنش‌ها حذف خواهند شد</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php view('layouts.footer') ?>
