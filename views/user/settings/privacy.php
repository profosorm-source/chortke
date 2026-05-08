<?php view('layouts.header', ['title' => $title]) ?>

<div class="container py-4">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <div class="list-group">
                <a href="<?= url('/settings/general') ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-cog me-2"></i> تنظیمات عمومی
                </a>
                <a href="<?= url('/settings/privacy') ?>" class="list-group-item list-group-item-action active">
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
                    <form method="POST" action="<?= url('/settings/privacy/update') ?>">
                        <?= csrf_field() ?>

                        <!-- Profile Visibility -->
                        <div class="mb-4">
                            <h5 class="mb-3">دید پروفایل</h5>
                            <div class="mb-3">
                                <label for="profile_visibility" class="form-label">دید پروفایل</label>
                                <select id="profile_visibility" name="profile_visibility" class="form-select">
                                    <?php foreach ($visibility_options as $code => $name): ?>
                                        <option value="<?= h($code) ?>" <?= $settings['profile_visibility'] === $code ? 'selected' : '' ?>>
                                            <?= h($name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">تعیین کنید که چه کسی می‌تواند پروفایل شما را ببیند</small>
                            </div>
                        </div>

                        <!-- Activity & Status -->
                        <div class="mb-4">
                            <h5 class="mb-3">وضعیت و فعالیت</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="show_online_status" 
                                       name="show_online_status" <?= $settings['show_online_status'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="show_online_status">
                                    نمایش وضعیت آنلاین
                                </label>
                                <small class="text-muted d-block">دیگران می‌توانند ببینند که فعلاً آنلاین هستید</small>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="show_activity" 
                                       name="show_activity" <?= $settings['show_activity'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="show_activity">
                                    نمایش فعالیت
                                </label>
                                <small class="text-muted d-block">دیگران می‌توانند آخرین فعالیت شما را ببینند</small>
                            </div>
                        </div>

                        <!-- Communication -->
                        <div class="mb-4">
                            <h5 class="mb-3">ارتباط</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="allow_messages" 
                                       name="allow_messages" <?= $settings['allow_messages'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="allow_messages">
                                    اجازه دریافت پیام‌های مستقیم
                                </label>
                                <small class="text-muted d-block">دیگران می‌توانند برای شما پیام ارسال کنند</small>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="allow_friend_requests" 
                                       name="allow_friend_requests" <?= $settings['allow_friend_requests'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="allow_friend_requests">
                                    اجازه درخواست‌های دوستی
                                </label>
                                <small class="text-muted d-block">دیگران می‌توانند درخواست دوستی برای شما ارسال کنند</small>
                            </div>
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
