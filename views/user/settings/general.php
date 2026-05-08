<?php view('layouts.header', ['title' => $title]) ?>

<div class="container py-4">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <div class="list-group">
                <a href="<?= url('/settings/general') ?>" class="list-group-item list-group-item-action active">
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
                    <form method="POST" action="<?= url('/settings/general/update') ?>">
                        <?= csrf_field() ?>

                        <!-- Language -->
                        <div class="mb-3">
                            <label for="language" class="form-label">زبان</label>
                            <select id="language" name="language" class="form-select">
                                <?php foreach ($languages as $code => $name): ?>
                                    <option value="<?= h($code) ?>" <?= $settings['language'] === $code ? 'selected' : '' ?>>
                                        <?= h($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">زبان پیش‌فرض رابط کاربری را انتخاب کنید</small>
                        </div>

                        <!-- Timezone -->
                        <div class="mb-3">
                            <label for="timezone" class="form-label">منطقه زمانی</label>
                            <select id="timezone" name="timezone" class="form-select" style="max-height: 300px;">
                                <?php foreach ($timezones as $tz): ?>
                                    <option value="<?= h($tz) ?>" <?= $settings['timezone'] === $tz ? 'selected' : '' ?>>
                                        <?= h($tz) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">منطقه زمانی برای نمایش اوقات انتخاب کنید</small>
                        </div>

                        <!-- Theme -->
                        <div class="mb-3">
                            <label for="theme" class="form-label">تم</label>
                            <select id="theme" name="theme" class="form-select">
                                <?php foreach ($themes as $code => $name): ?>
                                    <option value="<?= h($code) ?>" <?= $settings['theme'] === $code ? 'selected' : '' ?>>
                                        <?= h($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">تم رابط کاربری را انتخاب کنید</small>
                        </div>

                        <!-- Date Format -->
                        <div class="mb-3">
                            <label for="date_format" class="form-label">قالب تاریخ</label>
                            <select id="date_format" name="date_format" class="form-select">
                                <?php foreach ($date_formats as $code => $name): ?>
                                    <option value="<?= h($code) ?>" <?= $settings['date_format'] === $code ? 'selected' : '' ?>>
                                        <?= h($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">قالب تاریخ در نمایش‌ها انتخاب کنید</small>
                        </div>

                        <!-- Items Per Page -->
                        <div class="mb-3">
                            <label for="items_per_page" class="form-label">تعداد آیتم در هر صفحه</label>
                            <input type="number" id="items_per_page" name="items_per_page" 
                                   class="form-control" min="10" max="100"
                                   value="<?= (int)$settings['items_per_page'] ?>">
                            <small class="text-muted">تعداد آیتم‌های نمایش‌شده در لیست‌ها</small>
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
