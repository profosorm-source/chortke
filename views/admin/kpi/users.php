<?php $title = 'آنالیتیکس کاربران'; $layout = 'admin'; ob_start(); ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><span class="material-icons me-1" style="vertical-align:middle;">groups</span> آنالیتیکس کاربران</h4>
        <a href="<?= url('/admin/kpi') ?>" class="btn btn-sm btn-outline-secondary">بازگشت به KPI</a>
    </div>

    <!-- آمار اصلی -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-primary" style="font-size:28px;font-weight:bold;"><?= number_format($userStats['total']) ?></div><small class="text-muted">کل</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-success" style="font-size:28px;font-weight:bold;"><?= number_format($userStats['active']) ?></div><small class="text-muted">فعال</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-info" style="font-size:28px;font-weight:bold;"><?= e($userStats['new_today']) ?></div><small class="text-muted">جدید امروز</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-warning" style="font-size:28px;font-weight:bold;"><?= e($userStats['new_this_week']) ?></div><small class="text-muted">جدید هفته</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-danger" style="font-size:28px;font-weight:bold;"><?= number_format($userStats['banned']) ?></div><small class="text-muted">مسدود</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div style="font-size:28px;font-weight:bold;color:#9c27b0;"><?= e($userStats['kyc_pending']) ?></div><small class="text-muted">KYC در انتظار</small></div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- نمودار ثبت‌نام -->
        <div class="col-lg-8 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">ثبت‌نام روزانه (۳۰ روز)</h6></div>
                <div class="card-body"><canvas id="regChart" height="280"></canvas></div>
            </div>
        </div>
        <!-- قرعه‌کشی -->
        <div class="col-lg-4 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">قرعه‌کشی</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span>شرکت‌کنندگان فعال:</span><strong><?= number_format($lotteryStats['total_participants']) ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>رأی امروز:</span><strong><?= number_format($lotteryStats['votes_today']) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>میانگین امتیاز شانس:</span><strong><?= e($lotteryStats['avg_chance_score']) ?></strong></div>
                </div>
            </div>
        </div>
    </div>

    <!-- کاربران برتر -->
    <div class="card">
        <div class="card-header"><h6 class="mb-0">کاربران برتر (بر اساس درآمد)</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>#</th><th>نام</th><th>ایمیل</th><th>سطح</th><th>درآمد تسک</th><th>کمیسیون</th><th>مجموع</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topUsers as $i => $u): ?>
                            <?php $usr = \is_array($u) ? (object)$u : $u; ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= e($usr->full_name ?? '') ?></strong></td>
                                <td><small><?= e($usr->email ?? '') ?></small></td>
                                <td><span class="badge bg-info"><?= e($usr->tier_level ?? 'silver') ?></span></td>
                                <td><?= number_format((float)($usr->task_earnings ?? 0)) ?></td>
                                <td><?= number_format((float)($usr->commission_earnings ?? 0)) ?></td>
                                <td><strong><?= number_format((float)($usr->task_earnings ?? 0) + (float)($usr->commission_earnings ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topUsers)): ?>
                            <tr><td colspan="7" class="text-center py-3 text-muted">داده‌ای موجود نیست</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var regData = <?= \json_encode($dailyReg) ?>;
    new Chart(document.getElementById('regChart'), {
        type: 'line',
        data: {
            labels: regData.map(function(d) { return d.date; }),
            datasets: [{
                label: 'ثبت‌نام', data: regData.map(function(d) { return parseInt(d.count); }),
                borderColor: '#4fc3f7', backgroundColor: 'rgba(79,195,247,0.15)',
                tension: 0.4, fill: true, pointRadius: 3, pointBackgroundColor: '#4fc3f7'
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>