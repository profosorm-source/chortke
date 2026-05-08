<?php $title = 'آنالیتیکس مالی'; $layout = 'admin'; ob_start(); ?>

<?php
$curr = $financialStats['currency'] ?? 'irt';
function fmtMoney($amount, $c) {
    if ($c === 'usdt') return number_format((float)$amount, 2) . ' USDT';
    return number_format((float)$amount) . ' تومان';
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><span class="material-icons me-1" style="vertical-align:middle;">payments</span> آنالیتیکس مالی</h4>
        <a href="<?= url('/admin/kpi') ?>" class="btn btn-sm btn-outline-secondary">بازگشت به KPI</a>
    </div>

    <!-- آمار مالی -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background:linear-gradient(135deg,#4caf50,#43a047);"></div>
                <div class="card-body p-3">
                    <div class="stat-label">کل واریزها</div>
                    <div class="stat-value" style="font-size:16px;"><?= fmtMoney($financialStats['total_deposits'], $curr) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background:linear-gradient(135deg,#f44336,#e53935);"></div>
                <div class="card-body p-3">
                    <div class="stat-label">کل برداشت‌ها</div>
                    <div class="stat-value" style="font-size:16px;"><?= fmtMoney($financialStats['total_withdrawals'], $curr) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background:linear-gradient(135deg,#9c27b0,#7b1fa2);"></div>
                <div class="card-body p-3">
                    <div class="stat-label">درآمد سایت (کل)</div>
                    <div class="stat-value" style="font-size:16px;"><?= fmtMoney($financialStats['site_revenue'], $curr) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background:linear-gradient(135deg,#ffa726,#ff9800);"></div>
                <div class="card-body p-3">
                    <div class="stat-label">گردش خالص</div>
                    <div class="stat-value" style="font-size:16px;color:<?= $financialStats['net_flow'] >= 0 ? '#4caf50' : '#f44336' ?>;">
                        <?= fmtMoney($financialStats['net_flow'], $curr) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نمودار واریز/برداشت -->
    <div class="row mb-4">
        <div class="col-lg-8 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">واریز و برداشت روزانه (۳۰ روز)</h6></div>
                <div class="card-body"><canvas id="dwChart" height="280"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">درآمد روزانه</h6></div>
                <div class="card-body"><canvas id="dailyRevenueChart" height="280"></canvas></div>
            </div>
        </div>
    </div>

    <!-- سرمایه‌گذاری + Referral -->
    <div class="row">
        <div class="col-lg-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">سرمایه‌گذاری</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span>کل سرمایه‌گذاری‌ها:</span><strong><?= e($investmentStats['total']) ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>فعال:</span><strong class="text-success"><?= e($investmentStats['active']) ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>کل سرمایه:</span><strong><?= fmtMoney($investmentStats['total_invested'], 'usdt') ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>کل سود:</span><strong class="text-success"><?= fmtMoney($investmentStats['total_profit'], 'usdt') ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>کل ضرر:</span><strong class="text-danger"><?= fmtMoney($investmentStats['total_loss'], 'usdt') ?></strong></div>
                    <div class="d-flex justify-content-between"><span>سود خالص:</span><strong style="color:<?= $investmentStats['net_profit'] >= 0 ? '#4caf50' : '#f44336' ?>"><?= fmtMoney($investmentStats['net_profit'], 'usdt') ?></strong></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">سیستم معرفی (Referral)</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span>کل معرفی‌ها:</span><strong><?= number_format($referralStats['total']) ?></strong></div>
                    <div class="d-flex justify-content-between mb-3"><span>کل کمیسیون:</span><strong><?= fmtMoney($referralStats['total_commissions'], $curr) ?></strong></div>
                    <?php if (!empty($referralStats['top_referrers'])): ?>
                        <h6 style="font-size:13px;">برترین معرف‌ها:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0" style="font-size:12px;">
                                <thead><tr><th>نام</th><th>تعداد</th><th>کمیسیون</th></tr></thead>
                                <tbody>
                                    <?php foreach (\array_slice($referralStats['top_referrers'], 0, 5) as $tr): ?>
                                        <?php $r = \is_array($tr) ? (object)$tr : $tr; ?>
                                        <tr>
                                            <td><?= e($r->full_name ?? '') ?></td>
                                            <td><?= $r->referral_count ?? 0 ?></td>
                                            <td><?= fmtMoney($r->total_earned ?? 0, $curr) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // واریز/برداشت
    var dwData = <?= \json_encode($dailyDW) ?>;
    var depDates = (dwData.deposits || []).map(function(d) { return d.date; });
    var depValues = (dwData.deposits || []).map(function(d) { return parseFloat(d.total); });
    var wdDates = (dwData.withdrawals || []).map(function(d) { return d.date; });
    var wdValues = (dwData.withdrawals || []).map(function(d) { return parseFloat(d.total); });

    var allDates = [...new Set([...depDates, ...wdDates])].sort();
    var depMap = {}; depDates.forEach(function(d, i) { depMap[d] = depValues[i]; });
    var wdMap = {}; wdDates.forEach(function(d, i) { wdMap[d] = wdValues[i]; });

    new Chart(document.getElementById('dwChart'), {
        type: 'line',
        data: {
            labels: allDates,
            datasets: [
                { label: 'واریز', data: allDates.map(function(d) { return depMap[d] || 0; }), borderColor: '#4caf50', backgroundColor: 'rgba(76,175,80,0.1)', tension: 0.4, fill: true },
                { label: 'برداشت', data: allDates.map(function(d) { return wdMap[d] || 0; }), borderColor: '#f44336', backgroundColor: 'rgba(244,67,54,0.1)', tension: 0.4, fill: true }
            ]
        },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });

    // درآمد روزانه
    var revData = <?= \json_encode($dailyRevenue) ?>;
    new Chart(document.getElementById('dailyRevenueChart'), {
        type: 'bar',
        data: {
            labels: revData.map(function(d) { return d.date; }),
            datasets: [{ label: 'درآمد', data: revData.map(function(d) { return parseFloat(d.total); }), backgroundColor: 'rgba(156,39,176,0.6)', borderColor: '#9c27b0', borderWidth: 1, borderRadius: 4 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>