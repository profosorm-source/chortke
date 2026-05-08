<?php
$title = $title ?? 'جزئیات آگهی';
$layout = 'user';
$ad = $data['ad'];
$stats = $data['stats'];
$predictions = $data['predictions'];
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-seo-ad.css') ?>">

<div class="page-header">
    <h4><i class="material-icons">analytics</i> <?= e($ad->title) ?></h4>
    <div class="header-actions">
        <a href="<?= url('/seo-ad/' . $ad->id . '/export-csv') ?>" class="btn btn-secondary">
            <i class="material-icons">download</i> دانلود CSV
        </a>
        <a href="<?= url('/seo-ad') ?>" class="btn btn-info">بازگشت</a>
    </div>
</div>

<div class="ad-details-grid">
    <div class="detail-card">
        <h5>اطلاعات آگهی</h5>
        <div class="detail-row">
            <span class="label">کلمه کلیدی:</span>
            <span class="value"><strong><?= e($ad->keyword) ?></strong></span>
        </div>
        <div class="detail-row">
            <span class="label">آدرس سایت:</span>
            <span class="value"><a href="<?= e($ad->site_url) ?>" target="_blank"><?= e($ad->site_url) ?></a></span>
        </div>
        <div class="detail-row">
            <span class="label">وضعیت:</span>
            <span class="value">
                <?php
                $statusLabels = [
                    'pending' => '<span class="badge badge-warning">در انتظار تایید</span>',
                    'active' => '<span class="badge badge-success">فعال</span>',
                    'paused' => '<span class="badge badge-secondary">متوقف</span>',
                    'rejected' => '<span class="badge badge-danger">رد شده</span>',
                    'exhausted' => '<span class="badge badge-dark">تمام شده</span>',
                ];
                echo $statusLabels[$ad->status] ?? $ad->status;
                ?>
            </span>
        </div>
        <?php if ($ad->deadline): ?>
        <div class="detail-row">
            <span class="label">انقضا:</span>
            <span class="value"><?= jdate('Y/m/d H:i', strtotime($ad->deadline)) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="detail-card">
        <h5>بودجه</h5>
        <div class="detail-row">
            <span class="label">بودجه کل:</span>
            <span class="value"><?= number_format($ad->budget) ?> تومان</span>
        </div>
        <div class="detail-row">
            <span class="label">باقیمانده:</span>
            <span class="value"><strong><?= number_format($ad->remaining_budget) ?> تومان</strong></span>
        </div>
        <div class="detail-row">
            <span class="label">هزینه شده:</span>
            <span class="value"><?= number_format($stats->total_spent) ?> تومان</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?= round(($ad->remaining_budget / $ad->budget) * 100) ?>%"></div>
        </div>
    </div>

    <div class="detail-card">
        <h5>تنظیمات پرداخت</h5>
        <div class="detail-row">
            <span class="label">حداقل پرداخت:</span>
            <span class="value"><?= number_format($ad->min_payout) ?> تومان</span>
        </div>
        <div class="detail-row">
            <span class="label">حداکثر پرداخت:</span>
            <span class="value"><?= number_format($ad->max_payout) ?> تومان</span>
        </div>
        <div class="detail-row">
            <span class="label">میانگین پرداخت:</span>
            <span class="value"><?= number_format($predictions['avg_payout']) ?> تومان</span>
        </div>
    </div>

    <div class="detail-card">
        <h5>آمار اجرا</h5>
        <div class="detail-row">
            <span class="label">کل اجراها:</span>
            <span class="value"><?= $stats->total_executions ?></span>
        </div>
        <div class="detail-row">
            <span class="label">تکمیل شده:</span>
            <span class="value text-success"><?= $stats->completed ?></span>
        </div>
        <div class="detail-row">
            <span class="label">تقلب:</span>
            <span class="value text-danger"><?= $stats->fraud_count ?></span>
        </div>
        <div class="detail-row">
            <span class="label">نرخ تکمیل:</span>
            <span class="value"><?= $predictions['completion_rate'] ?>%</span>
        </div>
    </div>
</div>

<div class="stats-row mt-20">
    <div class="stat-card stat-blue">
        <span class="stat-num"><?= round($stats->avg_score, 1) ?></span>
        <span class="stat-lbl">میانگین امتیاز</span>
    </div>
    <div class="stat-card stat-green">
        <span class="stat-num"><?= $stats->completed ?></span>
        <span class="stat-lbl">تعامل موفق</span>
    </div>
    <div class="stat-card stat-orange">
        <span class="stat-num"><?= number_format($predictions['avg_payout']) ?></span>
        <span class="stat-lbl">میانگین پرداخت</span>
    </div>
    <div class="stat-card stat-purple">
        <span class="stat-num"><?= number_format($predictions['estimated_reach']) ?></span>
        <span class="stat-lbl">تخمین کاربران باقیمانده</span>
    </div>
</div>

<?php if (!empty($data['score_distribution'])): ?>
<div class="chart-section mt-20">
    <h5>توزیع امتیازات</h5>
    <canvas id="scoreChart" width="400" height="200"></canvas>
</div>
<?php endif; ?>

<?php if (!empty($data['timeline'])): ?>
<div class="chart-section mt-20">
    <h5>روند زمانی (30 روز گذشته)</h5>
    <canvas id="timelineChart" width="400" height="200"></canvas>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
<?php if (!empty($data['score_distribution'])): ?>
// Score Distribution Chart
const scoreData = <?= json_encode($data['score_distribution']) ?>;
const scoreLabels = scoreData.map(d => `${d.score_range}-${d.score_range + 10}`);
const scoreCounts = scoreData.map(d => d.count);

new Chart(document.getElementById('scoreChart'), {
    type: 'bar',
    data: {
        labels: scoreLabels,
        datasets: [{
            label: 'تعداد',
            data: scoreCounts,
            backgroundColor: 'rgba(54, 162, 235, 0.5)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false }
        }
    }
});
<?php endif; ?>

<?php if (!empty($data['timeline'])): ?>
// Timeline Chart
const timelineData = <?= json_encode(array_reverse($data['timeline'])) ?>;
const timelineLabels = timelineData.map(d => d.date);
const timelineExecutions = timelineData.map(d => d.executions);
const timelineSpent = timelineData.map(d => d.spent);

new Chart(document.getElementById('timelineChart'), {
    type: 'line',
    data: {
        labels: timelineLabels,
        datasets: [{
            label: 'تعداد اجرا',
            data: timelineExecutions,
            borderColor: 'rgba(75, 192, 192, 1)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            yAxisID: 'y'
        }, {
            label: 'هزینه (تومان)',
            data: timelineSpent,
            borderColor: 'rgba(255, 99, 132, 1)',
            backgroundColor: 'rgba(255, 99, 132, 0.2)',
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { type: 'linear', position: 'left' },
            y1: { type: 'linear', position: 'right', grid: { drawOnChartArea: false } }
        }
    }
});
<?php endif; ?>
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
