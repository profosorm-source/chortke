<?php
$pageTitle = 'آمار اعلان‌ها';
ob_start();
?>

<!-- فیلتر بازه زمانی -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <h5 class="mb-0"><i class="fas fa-chart-bar"></i> آمار و آنالیتیکس اعلان‌ها</h5>
    <div class="d-flex gap-2">
        <?php foreach ([7, 14, 30, 90] as $d): ?>
            <a href="?days=<?= $d ?>"
               class="btn btn-sm <?= $days == $d ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <?= $d ?> روز
            </a>
        <?php endforeach; ?>
        <a href="<?= url('/admin/notifications/send') ?>" class="btn btn-sm btn-success ms-2">
            <i class="fas fa-paper-plane"></i> ارسال جدید
        </a>
    </div>
</div>

<?php $ov = $dashboard['overview'] ?? []; ?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="text-muted small">کل ارسال‌شده</div>
            <div class="fs-3 fw-bold text-primary"><?= number_format((int)($ov['total_sent'] ?? 0)) ?></div>
            <div class="text-muted small"><?= $days ?> روز اخیر</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="text-muted small">نرخ خوانده‌شدن</div>
            <div class="fs-3 fw-bold text-success"><?= number_format((float)($ov['read_rate'] ?? 0), 1) ?>%</div>
            <div class="text-muted small"><?= number_format((int)($ov['total_read'] ?? 0)) ?> خوانده</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="text-muted small">نرخ کلیک (CTR)</div>
            <div class="fs-3 fw-bold text-warning"><?= number_format((float)($ov['ctr'] ?? 0), 1) ?>%</div>
            <div class="text-muted small"><?= number_format((int)($ov['total_clicked'] ?? 0)) ?> کلیک</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="text-muted small">انباشت نخوانده</div>
            <div class="fs-3 fw-bold text-danger"><?= number_format((int)($ov['unread_backlog'] ?? 0)) ?></div>
            <div class="text-muted small">در کل سیستم</div>
        </div>
    </div>
</div>

<!-- Funnel -->
<?php $funnel = $dashboard['funnel'] ?? []; ?>
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">قیف تعامل</h6></div>
    <div class="card-body">
        <div class="row text-center g-0">
            <?php
            $steps = [
                ['label' => 'ارسال‌شده',    'val' => $funnel['sent']        ?? 0, 'icon' => 'fa-paper-plane',  'color' => 'primary'],
                ['label' => 'خوانده‌شده',   'val' => $funnel['opened']      ?? 0, 'icon' => 'fa-envelope-open','color' => 'success'],
                ['label' => 'کلیک‌شده',     'val' => $funnel['clicked']     ?? 0, 'icon' => 'fa-mouse-pointer', 'color' => 'warning'],
            ];
            ?>
            <?php foreach ($steps as $i => $step): ?>
            <div class="col">
                <div class="py-3">
                    <div class="text-<?= $step['color'] ?> mb-2">
                        <i class="fas <?= $step['icon'] ?> fa-2x"></i>
                    </div>
                    <div class="fs-4 fw-bold"><?= number_format((int)$step['val']) ?></div>
                    <div class="text-muted small"><?= $step['label'] ?></div>
                </div>
            </div>
            <?php if ($i < count($steps) - 1): ?>
            <div class="col-auto d-flex align-items-center">
                <i class="fas fa-arrow-left text-muted fa-2x"></i>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-around mt-2 pt-2 border-top text-center">
            <div class="small text-muted">
                نرخ باز کردن: <strong class="text-success"><?= number_format((float)($funnel['open_rate'] ?? 0), 1) ?>%</strong>
            </div>
            <div class="small text-muted">
                CTR کلی: <strong class="text-warning"><?= number_format((float)($funnel['overall_ctr'] ?? 0), 1) ?>%</strong>
            </div>
            <div class="small text-muted">
                کلیک پس از باز کردن: <strong class="text-primary"><?= number_format((float)($funnel['click_after_read_rate'] ?? 0), 1) ?>%</strong>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">

    <!-- آمار per-type -->
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">آمار بر اساس نوع</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>نوع</th>
                                <th class="text-center">ارسال</th>
                                <th class="text-center">خوانده</th>
                                <th class="text-center">CTR</th>
                                <th class="text-center">میانگین زمان باز</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dashboard['by_type'] ?? [] as $row): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary"><?= e($row->type) ?></span>
                                </td>
                                <td class="text-center"><?= number_format((int)$row->total_sent) ?></td>
                                <td class="text-center">
                                    <span class="text-success"><?= number_format((float)$row->read_rate, 1) ?>%</span>
                                </td>
                                <td class="text-center">
                                    <span class="text-warning"><?= number_format((float)$row->ctr, 1) ?>%</span>
                                </td>
                                <td class="text-center text-muted small">
                                    <?php
                                    $sec = (int)($row->avg_time_to_read_sec ?? 0);
                                    echo $sec > 0 ? gmdate('H:i:s', $sec) : '—';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- آمار per-channel -->
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">آمار کانال‌ها</h6></div>
            <div class="card-body">
                <?php
                $channelIcons = [
                    'in_app' => ['icon' => 'fa-bell',      'color' => 'primary',  'label' => 'داخل سایت'],
                    'push'   => ['icon' => 'fa-mobile-alt','color' => 'success',  'label' => 'Push'],
                    'email'  => ['icon' => 'fa-envelope',  'color' => 'warning',  'label' => 'ایمیل'],
                    'sms'    => ['icon' => 'fa-sms',       'color' => 'info',     'label' => 'پیامک'],
                ];
                ?>
                <?php foreach ($dashboard['channels'] ?? [] as $ch): ?>
                <?php $ci = $channelIcons[$ch->channel] ?? ['icon' => 'fa-question', 'color' => 'secondary', 'label' => $ch->channel]; ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="text-<?= $ci['color'] ?>" style="width:24px">
                        <i class="fas <?= $ci['icon'] ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between small">
                            <span><?= $ci['label'] ?></span>
                            <span class="text-muted"><?= number_format((int)$ch->total_sent) ?></span>
                        </div>
                        <div class="progress" style="height:6px">
                            <?php
                            $total = array_sum(array_column((array)($dashboard['channels'] ?? []), 'total_sent'));
                            $pct   = $total > 0 ? round(($ch->total_sent / $total) * 100) : 0;
                            ?>
                            <div class="progress-bar bg-<?= $ci['color'] ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <div class="text-muted small" style="min-width:40px">
                        <?= $pct ?>%
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<div class="row g-4 mb-4">

    <!-- روند روزانه -->
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">روند روزانه</h6></div>
            <div class="card-body">
                <canvas id="dailyChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- Notification Fatigue -->
    <div class="col-md-4">
        <?php $fatigue = $dashboard['fatigue'] ?? []; $fatSummary = $fatigue['summary'] ?? []; ?>
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0">Notification Fatigue</h6>
                <?php if (!empty($fatSummary['affected_users'])): ?>
                <span class="badge bg-danger"><?= (int)$fatSummary['affected_users'] ?> کاربر</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="small text-muted mb-1">میانگین نوتیف نخوانده per user</div>
                    <div class="fs-5 fw-bold"><?= number_format((float)($fatSummary['avg_unread_per_user'] ?? 0), 1) ?></div>
                </div>
                <div class="mb-3">
                    <div class="small text-muted mb-1">بیشترین انباشت</div>
                    <div class="fs-5 fw-bold text-danger"><?= number_format((int)($fatSummary['max_unread'] ?? 0)) ?></div>
                </div>
                <?php if (!empty($fatigue['users'])): ?>
                <div class="small text-muted mb-2">کاربران با بیشترین انباشت:</div>
                <?php foreach (array_slice($fatigue['users'], 0, 5) as $fu): ?>
                <div class="d-flex justify-content-between small py-1 border-bottom">
                    <span class="text-muted">User #<?= (int)$fu->user_id ?></span>
                    <span class="badge bg-danger"><?= (int)$fu->unread_count ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- Segment analysis -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">آمار بر اساس Segment کاربر</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>KYC</th>
                        <th>سطح</th>
                        <th>وضعیت</th>
                        <th class="text-center">ارسال</th>
                        <th class="text-center">نرخ خوانده</th>
                        <th class="text-center">CTR</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dashboard['segment'] ?? [] as $row): ?>
                <tr>
                    <td><span class="badge bg-<?= $row->kyc_status === 'approved' ? 'success' : ($row->kyc_status === 'pending' ? 'warning' : 'secondary') ?>"><?= e($row->kyc_status ?? '—') ?></span></td>
                    <td><?= e($row->level ?? '—') ?></td>
                    <td><?= e($row->user_status ?? '—') ?></td>
                    <td class="text-center"><?= number_format((int)$row->total_sent) ?></td>
                    <td class="text-center text-success"><?= number_format((float)$row->read_rate, 1) ?>%</td>
                    <td class="text-center text-warning"><?= number_format((float)$row->ctr, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Top/Least engaged -->
<div class="row g-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">📈 بیشترین تعامل</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>کاربر</th><th>دریافت</th><th>خوانده</th><th>Read Rate</th></tr></thead>
                        <tbody>
                        <?php foreach ($dashboard['top_engaged'] ?? [] as $u): ?>
                        <tr>
                            <td class="small"><?= e($u->full_name ?: 'User #' . $u->user_id) ?></td>
                            <td><?= (int)$u->total_received ?></td>
                            <td><?= (int)$u->total_read ?></td>
                            <td class="text-success"><?= number_format((float)$u->read_rate, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">📉 کمترین تعامل</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>کاربر</th><th>دریافت</th><th>خوانده</th><th>Read Rate</th></tr></thead>
                        <tbody>
                        <?php foreach ($dashboard['least_engaged'] ?? [] as $u): ?>
                        <tr>
                            <td class="small"><?= e($u->email ?: 'User #' . $u->user_id) ?></td>
                            <td><?= (int)$u->total_received ?></td>
                            <td><?= (int)$u->total_read ?></td>
                            <td class="text-danger"><?= number_format((float)$u->read_rate, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
(function () {
    const daily = <?= json_encode(array_reverse($dashboard['daily_trend'] ?? []), JSON_UNESCAPED_UNICODE) ?>;

    if (!daily.length) return;

    const labels  = daily.map(d => d.date);
    const sent    = daily.map(d => parseInt(d.sent));
    const read    = daily.map(d => parseInt(d.read_count));
    const clicked = daily.map(d => parseInt(d.click_count));

    const ctx = document.getElementById('dailyChart')?.getContext('2d');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'ارسال', data: sent,    borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.1)', tension: .4, fill: true },
                { label: 'خوانده', data: read,   borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.1)',  tension: .4, fill: false },
                { label: 'کلیک',  data: clicked, borderColor: '#ffc107', backgroundColor: 'rgba(255,193,7,.1)',  tension: .4, fill: false },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: {
                x: { ticks: { maxTicksLimit: 10 } },
                y: { beginAtZero: true }
            }
        }
    });
})();
</script>
<?php
$content = ob_get_clean();
require VIEW_PATH . '/layouts/admin.php';
?>
