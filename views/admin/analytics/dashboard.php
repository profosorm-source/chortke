<?php view('layouts.header', ['title' => $title]) ?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= h($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">این هفته</a>
                <a href="<?= url('/admin/analytics?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">این ماه</a>
                <a href="<?= url('/admin/analytics?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">این سال</a>
            </div>
        </div>
    </div>

    <!-- Key Metrics Cards -->
    <div class="row g-3 mb-4">
        <!-- Users -->
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted small m-0">کل کاربران</p>
                            <h3 class="m-0 text-primary"><?= number_format($data['users']['total_users']) ?></h3>
                            <small class="text-success">+<?= $data['users']['new_users'] ?> جدید</small>
                        </div>
                        <span style="font-size: 2rem;">👥</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Users -->
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted small m-0">کاربران فعال</p>
                            <h3 class="m-0 text-success"><?= number_format($data['users']['active_users']) ?></h3>
                            <small class="text-muted">7 روز گذشته</small>
                        </div>
                        <span style="font-size: 2rem;">🔥</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue -->
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted small m-0">درآمد خالص</p>
                            <h3 class="m-0 text-info"><?= number_format((int)$data['revenue']['net_profit']) ?></h3>
                            <small class="text-muted">تومان</small>
                        </div>
                        <span style="font-size: 2rem;">💰</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tasks -->
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted small m-0">کل وظایف</p>
                            <h3 class="m-0 text-warning"><?= number_format($data['social_tasks']['executions']['total']) ?></h3>
                            <small class="text-success"><?= $data['social_tasks']['executions']['approval_rate'] ?>% تایید</small>
                        </div>
                        <span style="font-size: 2rem;">✓</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Sections -->
    <div class="row g-3">
        <!-- Users Section -->
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">
                        <a href="<?= url('/admin/analytics/users') ?>" class="text-decoration-none">
                            👥 تحلیلات کاربران
                        </a>
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کل کاربران</td>
                            <td><strong><?= number_format($data['users']['total_users']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>کاربران فعال</td>
                            <td><strong><?= number_format($data['users']['active_users']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>KYC تأیید‌شده</td>
                            <td><strong><?= number_format($data['users']['kyc_verified']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>KYC منتظر</td>
                            <td><strong><?= number_format($data['users']['kyc_pending']) ?></strong></td>
                        </tr>
                    </table>
                    <a href="<?= url('/admin/analytics/users') ?>" class="btn btn-sm btn-outline-primary w-100">مشاهده جزئیات</a>
                </div>
            </div>
        </div>

        <!-- Transactions Section -->
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">
                        <a href="<?= url('/admin/analytics/transactions') ?>" class="text-decoration-none">
                            💳 تحلیلات تراکنش‌ها
                        </a>
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>واریز‌ها</td>
                            <td><strong><?= number_format($data['transactions']['deposits']['count']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>برداشت‌ها</td>
                            <td><strong><?= number_format($data['transactions']['withdrawals']['count']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>درآمد پلتفرم</td>
                            <td><strong><?= number_format((int)$data['transactions']['platform_fee']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>جریان خالص</td>
                            <td><strong class="text-<?= $data['transactions']['net_flow'] > 0 ? 'success' : 'danger' ?>">
                                <?= number_format((int)$data['transactions']['net_flow']) ?>
                            </strong></td>
                        </tr>
                    </table>
                    <a href="<?= url('/admin/analytics/transactions') ?>" class="btn btn-sm btn-outline-primary w-100">مشاهده جزئیات</a>
                </div>
            </div>
        </div>

        <!-- Social Tasks Section -->
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">
                        <a href="<?= url('/admin/analytics/social-tasks') ?>" class="text-decoration-none">
                            📱 وظایف اجتماعی
                        </a>
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>آگهی‌های فعال</td>
                            <td><strong><?= number_format($data['social_tasks']['ads']['active']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>کل اجراها</td>
                            <td><strong><?= number_format($data['social_tasks']['executions']['total']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>نرخ تایید</td>
                            <td><strong><?= $data['social_tasks']['executions']['approval_rate'] ?>%</strong></td>
                        </tr>
                        <tr>
                            <td>میانگین امتیاز</td>
                            <td><strong><?= number_format($data['social_tasks']['executions']['avg_score'], 2) ?>/۱۰</strong></td>
                        </tr>
                    </table>
                    <a href="<?= url('/admin/analytics/social-tasks') ?>" class="btn btn-sm btn-outline-primary w-100">مشاهده جزئیات</a>
                </div>
            </div>
        </div>

        <!-- Ratings Section -->
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">
                        <a href="<?= url('/admin/analytics/ratings') ?>" class="text-decoration-none">
                            ⭐ امتیازات و نظرات
                        </a>
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>کل امتیازات</td>
                            <td><strong><?= number_format($data['ratings']['total_ratings']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>میانگین امتیاز</td>
                            <td><strong><?= number_format($data['ratings']['average_rating'], 1) ?>/۵</strong></td>
                        </tr>
                        <tr>
                            <td>منتظر بررسی</td>
                            <td><strong><?= number_format($data['ratings']['moderation_status']['pending']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>تأیید‌شده</td>
                            <td><strong><?= number_format($data['ratings']['moderation_status']['approved']) ?></strong></td>
                        </tr>
                    </table>
                    <a href="<?= url('/admin/analytics/ratings') ?>" class="btn btn-sm btn-outline-primary w-100">مشاهده جزئیات</a>
                </div>
            </div>
        </div>

        <!-- Revenue Section -->
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">
                        <a href="<?= url('/admin/analytics/revenue') ?>" class="text-decoration-none">
                            💹 تحلیلات درآمد
                        </a>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h6 class="text-muted">درآمد کل</h6>
                            <h4 class="text-success"><?= number_format((int)$data['revenue']['income']['total']) ?></h4>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted">هزینه‌ها</h6>
                            <h4 class="text-danger"><?= number_format((int)$data['revenue']['expenses']['total']) ?></h4>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted">سود خالص</h6>
                            <h4 class="text-<?= $data['revenue']['net_profit'] > 0 ? 'success' : 'danger' ?>">
                                <?= number_format((int)$data['revenue']['net_profit']) ?>
                            </h4>
                        </div>
                        <div class="col-md-3">
                            <form method="POST" action="<?= url('/admin/analytics/export') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="period" value="<?= h($period) ?>">
                                <button type="submit" name="format" value="pdf" class="btn btn-sm btn-outline-secondary">
                                    📄 صادر PDF
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- System Health -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">
                        <a href="<?= url('/admin/analytics/system-health') ?>" class="text-decoration-none">
                            🔧 سلامت سیستم
                        </a>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>حجم دیتابیس:</strong>
                            <?= $data['system_health']['database_size_mb'] ?> MB
                        </div>
                        <div class="col-md-4">
                            <strong>خطاهای اخیر:</strong>
                            <?= count($data['system_health']['recent_errors']) ?> نوع خطا
                        </div>
                        <div class="col-md-4">
                            <strong>تجاوز rate limit (24 ساعت):</strong>
                            <?= $data['system_health']['rate_limit_hits'] ?> بار
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php view('layouts.footer') ?>
