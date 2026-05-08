<?php
$pageTitle = 'داشبورد ضد تقلب';
ob_start();
?>

<div class="container-fluid">
    <h4 class="mb-4">داشبورد ضد تقلب (Anti-Fraud)</h4>

    <!-- آمار -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: rgba(244, 67, 54, 0.1);">
                    <i class="material-icons" style="color: #f44336;">warning</i>
                </div>
                <div class="stats-content">
                    <h3><?= e($stats['high_risk_users']) ?></h3>
                    <span>کاربران پرریسک</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: rgba(0, 0, 0, 0.1);">
                    <i class="material-icons">block</i>
                </div>
                <div class="stats-content">
                    <h3><?= e($stats['blacklisted_users']) ?></h3>
                    <span>لیست سیاه</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: rgba(255, 152, 0, 0.1);">
                    <i class="material-icons" style="color: #ff9800;">report_problem</i>
                </div>
                <div class="stats-content">
                    <h3><?= e($stats['today_suspicious']) ?></h3>
                    <span>فعالیت مشکوک امروز</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: rgba(244, 67, 54, 0.1);">
                    <i class="material-icons" style="color: #f44336;">cancel</i>
                </div>
                <div class="stats-content">
                    <h3><?= e($stats['today_rejected']) ?></h3>
                    <span>تسک رد شده امروز</span>
                </div>
            </div>
        </div>
    </div>

    <!-- فعالیت‌های مشکوک -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">فعالیت‌های مشکوک اخیر</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>اقدام</th>
                            <th>توضیحات</th>
                            <th>زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSuspicious as $activity): ?>
                        <tr>
                            <td>
                                <strong><?= e($activity->full_name) ?></strong><br>
                                <small class="text-muted"><?= e($activity->email) ?></small>
                            </td>
                            <td><code><?= e($activity->action) ?></code></td>
                            <td><?= e($activity->description ?? '-') ?></td>
                            <td><?= to_jalali($activity->created_at) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- IP های مشکوک -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">IP های مشکوک</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>IP</th>
                                    <th>تعداد کاربر</th>
                                    <th>جلسات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suspiciousIPs as $ip): ?>
                                <tr>
                                    <td><code><?= e($ip->ip_address) ?></code></td>
                                    <td><span class="badge badge-danger"><?= e($ip->user_count) ?></span></td>
                                    <td><?= e($ip->total_sessions) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fingerprint های تکراری -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Fingerprint های مشترک</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Fingerprint</th>
                                    <th>تعداد کاربر</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($duplicateFingerprints as $fp): ?>
                                <tr>
                                    <td><code><?= substr(e($fp->fingerprint), 0, 16) ?>...</code></td>
                                    <td><span class="badge badge-warning"><?= e($fp->user_count) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once view_path('layouts.admin');
?>