<?php
$title = 'تحلیل جامع آگهی';
$layout = 'user';
ob_start();

$typeLabel = match($ad->type){
    'banner' => 'بنری پلتفرم',
    'notification' => 'نوتیفیکیشن',
    'seo' => 'جستجوی سئو گوگل',
    'social_task' => 'شبکه‌های اجتماعی',
    'custom_task' => 'تسک اختصاصی',
    default => 'تبلیغ'
};
?>

<!-- Load Chart.js directly for interactive graphing -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="container py-4" dir="rtl">
    
    <!-- Top Nav & Header -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= url('/ads') ?>">آگهی‌های من</a></li>
            <li class="breadcrumb-item active" aria-current="page">جزئیات تحلیل</li>
        </ol>
    </nav>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3"><?= $typeLabel ?></span>
                <span class="badge bg-<?= $ad->status === 'active' ? 'success' : 'secondary' ?> rounded-pill border border-opacity-25 px-3">وضعیت: <?= e($ad->status) ?></span>
            </div>
            <h2 class="fw-bold mb-0"><?= e($ad->title) ?></h2>
        </div>
        <div class="mt-3 mt-md-0">
            <a href="<?= url('/ads/create') ?>" class="btn btn-light rounded-pill shadow-sm px-4">ثبت آگهی مشابه <i class="material-icons small align-middle ms-1">content_copy</i></a>
        </div>
    </div>

    <!-- Dashboard Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-3 p-3">
                        <i class="material-icons fs-3">account_balance_wallet</i>
                    </div>
                    <div>
                        <small class="text-muted d-block">بودجه باقی‌مانده</small>
                        <h4 class="fw-bold mb-0 text-indigo"><?= number_format((float)$ad->remaining_budget) ?> <small class="small" style="font-size:0.6em">ت</small></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-warning bg-opacity-10 text-warning rounded-3 p-3">
                        <i class="material-icons fs-3">visibility</i>
                    </div>
                    <div>
                        <small class="text-muted d-block">کل بازدیدها</small>
                        <h4 class="fw-bold mb-0"><?= number_format((int)($ad->impressions ?? 0)) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-danger bg-opacity-10 text-danger rounded-3 p-3">
                        <i class="material-icons fs-3">ads_click</i>
                    </div>
                    <div>
                        <small class="text-muted d-block">تعداد تعاملات (کلیک)</small>
                        <h4 class="fw-bold mb-0"><?= number_format((int)($ad->clicks ?? 0)) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-success bg-opacity-10 text-success rounded-3 p-3">
                        <i class="material-icons fs-3">fact_check</i>
                    </div>
                    <div>
                        <small class="text-muted d-block">ظرفیت باقیمانده</small>
                        <h4 class="fw-bold mb-0"><?= number_format((int)($ad->remaining_count ?? 0)) ?> <small class="small" style="font-size:0.6em">عدد</small></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Performance Chart -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-3 pb-0">
                    <h5 class="fw-bold mb-0"><i class="material-icons align-middle me-1 text-muted">timeline</i> نمودار عملکرد هفتگی</h5>
                </div>
                <div class="card-body">
                    <canvas id="performanceChart" height="120"></canvas>
                </div>
            </div>
        </div>

        <!-- Configuration Details -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-3 pb-0">
                    <h5 class="fw-bold mb-0"><i class="material-icons align-middle me-1 text-muted">settings_applications</i> جزئیات پیکربندی</h5>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between py-3 border-0">
                            <span class="text-muted small">هزینه هر عمل</span>
                            <span class="fw-bold"><?= number_format((float)($ad->price_per_task ?? $ad->cost_per_click ?? 0)) ?> تومان</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between py-3 border-bottom-0 border-top">
                            <span class="text-muted small">پلتفرم هدف</span>
                            <span class="badge bg-light text-dark border px-3 rounded-pill"><?= ucfirst(e($ad->platform ?? 'شبکه داخلی')) ?></span>
                        </li>
                        <?php if(!empty($ad->url)): ?>
                        <li class="list-group-item py-3 border-top">
                            <span class="text-muted small d-block mb-1">لینک هدف:</span>
                            <div class="bg-light rounded p-2 small text-truncate" dir="ltr">
                                <a href="<?= e($ad->url) ?>" target="_blank" class="text-decoration-none text-break"><?= e($ad->url) ?></a>
                            </div>
                        </li>
                        <?php endif; ?>
                         <li class="list-group-item d-flex justify-content-between py-3 border-top">
                            <span class="text-muted small">تاریخ انقضا</span>
                            <span class="fw-semibold"><?= !empty($ad->ends_at) ? date('Y/m/d', strtotime($ad->ends_at)) : 'بدون انقضا' ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Log & Executions Table -->
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0"><i class="material-icons align-middle me-1 text-muted">history</i> آخرین تراکنش‌ها و اجراها</h5>
                    <span class="badge bg-light text-muted rounded-pill fw-normal">نمایش ۵۰ رکورد آخر</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="border-0 px-4 small">شناسه</th>
                                <th class="border-0 small">کاربر انجام دهنده</th>
                                <th class="border-0 small">وضعیت اجرا</th>
                                <th class="border-0 small">تاریخ و زمان</th>
                                <th class="border-0 px-4 text-end small">جزئیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($executions)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="material-icons opacity-25 fs-1">inbox</i>
                                        <p class="mt-2 fw-bold small">هیچ اجرایی هنوز برای این آگهی ثبت نشده است.</p>
                                    </td>
                                </tr>
                            <?php else: foreach($executions as $ex): ?>
                                <tr>
                                    <td class="px-4 fw-bold text-muted">#<?= $ex->id ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar bg-soft-primary text-primary rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:0.7rem; background-color: #e0e7ff; font-weight: bold;">
                                                <?= mb_substr($ex->executor ?? 'ک', 0, 1) ?>
                                            </div>
                                            <span class="fw-semibold small"><?= e($ex->executor ?? 'کاربر ناشناس') ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                            $cls = match($ex->status) {
                                                'completed','approved' => 'success',
                                                'pending','started' => 'warning',
                                                'rejected','fraud' => 'danger',
                                                default => 'secondary'
                                            };
                                            $lbl = match($ex->status) {
                                                'completed','approved' => 'موفق',
                                                'pending' => 'در انتظار',
                                                'started' => 'شروع شده',
                                                'rejected' => 'رد شده',
                                                'fraud' => 'تقلب',
                                                default => $ex->status
                                            };
                                        ?>
                                        <span class="badge bg-<?= $cls ?> bg-opacity-10 text-<?= $cls ?> px-3 rounded-pill border border-<?= $cls ?> border-opacity-25"><?= $lbl ?></span>
                                    </td>
                                    <td class="small text-muted"><?= date('Y/m/d H:i', strtotime($ex->created_at)) ?></td>
                                    <td class="px-4 text-end">
                                        <button class="btn btn-link btn-sm p-0 text-decoration-none text-primary fw-bold" disabled>مشاهده مستندات</button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('performanceChart').getContext('2d');
    
    // Smooth Gradient creation
    let gradient = ctx.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(99, 102, 241, 0.3)');
    gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['روز ۱', 'روز ۲', 'روز ۳', 'روز ۴', 'روز ۵', 'روز ۶', 'امروز'],
            datasets: [{
                label: 'رشد بازدید',
                data: [12, 19, 3, 5, 2, 3, <?= (int)($ad->impressions ?? 0) ?>],
                borderColor: '#6366f1',
                backgroundColor: gradient,
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#ffffff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.03)' } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
