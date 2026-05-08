<?php
$title = 'لاگ فعالیت‌ها';
$layout = 'admin';
ob_start();

// اگر کنترلر متغیر را با نام دیگری پاس داد، سازگار کنیم
$logs = $logs ?? ($activities ?? []);
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">لاگ فعالیت‌ها</h5>
        <small class="text-muted">
            تعداد: <?= e(to_jalali((string)\count($logs), '', true)) ?>
        </small>
    </div>

    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="text-center py-5">
                <i class="material-icons text-muted" style="font-size: 60px;">receipt_long</i>
                <p class="text-muted mt-3 mb-0">هیچ لاگی ثبت نشده است</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>اکشن</th>
                            <th>توضیح</th>
                            <th>کاربر</th>
                            <th>ایمیل</th>
                            <th>IP</th>
                            <th>مرورگر</th>
                            <th>تاریخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            // decode metadata
                            $meta = [];
                            if (!empty($log->metadata)) {
                                $decoded = \json_decode($log->metadata, true);
                                if (\is_array($decoded)) $meta = $decoded;
                            }

                            $attemptEmail = $meta['email'] ?? null;

                            // ✅ تعیین نام و ایمیل نمایش
                            if (!empty($log->user_id)) {
                                // کاربر واقعی
                                $displayName  = $log->full_name ?? 'کاربر';
                                $displayEmail = $log->user_email ?? '-';
                            } else {
                                // user_id = NULL → یا ورود ناموفق یا سیستم
                                if (!empty($attemptEmail)) {
                                    $displayName  = 'ورود ناموفق: ' . $attemptEmail;
                                    $displayEmail = $attemptEmail;
                                } else {
                                    $displayName  = 'سیستم';
                                    $displayEmail = '-';
                                }
                            }

                            $ts = \strtotime($log->created_at);

// تاریخ شمسی
$jalaliDate = to_jalali(\date('Y-m-d', $ts));

// ساعت واقعی از created_at
$time = \date('H:i', $ts);

// تبدیل اعداد ساعت به فارسی
$timeFa = fa_digits($time);

$dateTime = $jalaliDate . ' ' . $timeFa;
                            // کوتاه کردن user agent
                            $ua = $log->user_agent ?? '-';
                            $uaShort = $ua !== '-' ? \mb_substr($ua, 0, 45) . '...' : '-';

                            // badge ساده برای بعضی اکشن‌ها
                            $action = $log->action ?? '-';
                            $badgeClass = 'bg-secondary';
                            if (\strpos($action, 'auth.login.success') !== false) $badgeClass = 'bg-success';
                            elseif (\strpos($action, 'auth.login.failed') !== false) $badgeClass = 'bg-danger';
                            elseif (\strpos($action, 'kyc.verify') !== false) $badgeClass = 'bg-success';
                            elseif (\strpos($action, 'kyc.reject') !== false) $badgeClass = 'bg-danger';
                            ?>
                            <tr>
                                <td><?= (int)$log->id ?></td>
                                <td>
                                    <span class="badge <?= e($badgeClass) ?>">
                                        <?= e($action) ?>
                                    </span>
                                </td>
                                <td><?= e($log->description ?? '-') ?></td>
                                <td><?= e($displayName) ?></td>
                                <td><?= e($displayEmail) ?></td>
                                <td><code><?= e($log->ip_address ?? '-') ?></code></td>
                                <td title="<?= e($ua) ?>">
                                    <small class="text-muted"><?= e($uaShort) ?></small>
                                </td>
                                <td><small><?= e($dateTime) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>