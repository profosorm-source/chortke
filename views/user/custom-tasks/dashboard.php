<?php
$title = $title ?? 'داشبورد آمار تسک‌های من';
$layout = 'user';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">
                <i class="fa fa-chart-line"></i> داشبورد آمار تسک‌های من
            </h2>
        </div>
    </div>

    <!-- کارت‌های آماری -->
    <div class="row mb-4">
        <?php
        $totalTasks = 0;
        $activeTasks = 0;
        $totalBudget = 0;
        $spentBudget = 0;
        
        foreach ($dashboard['tasks_by_status'] as $status) {
            $totalTasks += $status['count'];
            if ($status['status'] === 'active') {
                $activeTasks = $status['count'];
            }
            $totalBudget += $status['total_budget'] ?? 0;
            $spentBudget += $status['spent_budget'] ?? 0;
        }
        ?>
        
        <!-- کل تسک‌ها -->
        <div class="col-md-3 mb-3">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">کل تسک‌ها</h6>
                            <h3 class="mb-0"><?= number_format($totalTasks) ?></h3>
                        </div>
                        <div>
                            <i class="fa fa-tasks fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- تسک‌های فعال -->
        <div class="col-md-3 mb-3">
            <div class="card bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">تسک‌های فعال</h6>
                            <h3 class="mb-0"><?= number_format($activeTasks) ?></h3>
                        </div>
                        <div>
                            <i class="fa fa-check-circle fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- بودجه کل -->
        <div class="col-md-3 mb-3">
            <div class="card bg-info text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">بودجه کل</h6>
                            <h3 class="mb-0"><?= number_format($totalBudget) ?> تومان</h3>
                        </div>
                        <div>
                            <i class="fa fa-wallet fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- میانگین امتیاز -->
        <div class="col-md-3 mb-3">
            <div class="card bg-warning text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">میانگین امتیاز</h6>
                            <h3 class="mb-0">
                                <?= number_format($dashboard['rating']['avg_rating'] ?? 0, 1) ?>
                                <small>/5</small>
                            </h3>
                            <small>(<?= number_format($dashboard['rating']['total_ratings'] ?? 0) ?> نظر)</small>
                        </div>
                        <div>
                            <i class="fa fa-star fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نمودار وضعیت تسک‌ها -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">تسک‌ها به تفکیک وضعیت</h5>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>وضعیت</th>
                                <th>تعداد</th>
                                <th>بودجه</th>
                                <th>هزینه شده</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashboard['tasks_by_status'] as $row): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-<?= $statusClasses[$row['status']] ?? 'secondary' ?>">
                                            <?= $statusLabels[$row['status']] ?? $row['status'] ?>
                                        </span>
                                    </td>
                                    <td><?= number_format($row['count']) ?></td>
                                    <td><?= number_format($row['total_budget']) ?> تومان</td>
                                    <td><?= number_format($row['spent_budget']) ?> تومان</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- آمار Submission ها -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">آمار درخواست‌های انجام</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <h6 class="text-muted">کل درخواست‌ها</h6>
                            <h3><?= number_format($dashboard['submissions']['total_submissions']) ?></h3>
                        </div>
                        <div class="col-6 mb-3">
                            <h6 class="text-muted">در انتظار بررسی</h6>
                            <h3 class="text-warning"><?= number_format($dashboard['submissions']['pending_review']) ?></h3>
                        </div>
                        <div class="col-6 mb-3">
                            <h6 class="text-muted">تایید شده</h6>
                            <h3 class="text-success"><?= number_format($dashboard['submissions']['approved']) ?></h3>
                        </div>
                        <div class="col-6 mb-3">
                            <h6 class="text-muted">رد شده</h6>
                            <h3 class="text-danger"><?= number_format($dashboard['submissions']['rejected']) ?></h3>
                        </div>
                    </div>

                    <?php if ($dashboard['submissions']['avg_review_time_minutes']): ?>
                        <hr>
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="fa fa-clock"></i>
                                میانگین زمان بررسی: 
                                <strong><?= round($dashboard['submissions']['avg_review_time_minutes']) ?> دقیقه</strong>
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- دکمه‌های سریع -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">دسترسی سریع</h5>
                    <div class="btn-group-vertical btn-group-toggle w-100" role="group">
                        <a href="/user/custom-tasks/create" class="btn btn-primary mb-2">
                            <i class="fa fa-plus"></i> ایجاد تسک جدید
                        </a>
                        <a href="/user/custom-tasks" class="btn btn-outline-primary mb-2">
                            <i class="fa fa-list"></i> مشاهده همه تسک‌ها
                        </a>
                        <a href="/user/custom-tasks?status=pending_review" class="btn btn-outline-warning mb-2">
                            <i class="fa fa-hourglass-half"></i> تسک‌های در انتظار تایید (<?= $dashboard['submissions']['pending_review'] ?>)
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.opacity-50 {
    opacity: 0.5;
}
</style>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>